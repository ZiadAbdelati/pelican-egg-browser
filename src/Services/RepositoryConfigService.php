<?php

namespace Community\EggBrowser\Services;

use Community\EggBrowser\Models\PluginSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RepositoryConfigService
{
    public const SETTINGS_KEY = 'repositories';

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>
     */
    public function configuredRepositories(): array
    {
        $stored = $this->readStoredList();
        if ($stored !== null) {
            return $stored;
        }

        return $this->defaultMergedWithExtra();
    }

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>
     */
    public function enabledRepositories(): array
    {
        return array_values(array_filter(
            $this->configuredRepositories(),
            fn (array $repo) => !empty($repo['enabled'])
        ));
    }

    /**
     * Persist repository list from the free-form settings textarea.
     * Lines: owner/name[@branch] [#disabled] [#label=...]
     */
    public function persistRepositoryList(string $raw): void
    {
        $parsed = $this->parseRepositoryText($raw);

        if ($parsed === []) {
            $parsed = $this->defaultMergedWithExtra();
        }

        $this->writeStoredList($parsed);
    }

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>
     */
    public function defaultMergedWithExtra(): array
    {
        $defaults = collect(config('egg-browser.default_repositories', []))
            ->map(function (array $repo) {
                return [
                    'owner' => $repo['owner'],
                    'name' => $repo['name'],
                    'label' => $repo['label'] ?? $repo['name'],
                    'category' => $repo['category'] ?? $repo['name'],
                    'branch' => $repo['branch'] ?? 'main',
                    'enabled' => (bool) ($repo['enabled'] ?? true),
                    'official' => true,
                ];
            })
            ->keyBy(fn (array $repo) => strtolower($repo['owner'] . '/' . $repo['name']));

        foreach ($this->parseExtraEnvList() as $extra) {
            $key = strtolower($extra['owner'] . '/' . $extra['name']);
            if ($defaults->has($key)) {
                $existing = $defaults->get($key);
                $defaults->put($key, array_merge($existing, [
                    'branch' => $extra['branch'] ?: $existing['branch'],
                    'enabled' => $extra['enabled'],
                    'label' => $extra['label'] ?: $existing['label'],
                ]));
            } else {
                $defaults->put($key, $extra);
            }
        }

        return $defaults->values()->all();
    }

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>
     */
    public function parseRepositoryText(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $repos = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $enabled = true;
            $label = null;

            if (preg_match('/#\s*disabled\b/i', $line)) {
                $enabled = false;
            }
            if (preg_match('/#\s*label=([^\s#]+)/i', $line, $m)) {
                $label = str_replace('_', ' ', $m[1]);
            }

            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }

            // Accept full GitHub URLs
            if (preg_match('#github\.com[:/]([^/]+)/([^/@\s]+)#i', $line, $m)) {
                $owner = $m[1];
                $name = preg_replace('/\.git$/', '', $m[2]);
                $branch = 'main';
                if (preg_match('/@([A-Za-z0-9._\-\/]+)$/', $line, $bm)) {
                    $branch = $bm[1];
                }
            } elseif (preg_match('#^([^/\s]+)/([^/@\s]+)(?:@([A-Za-z0-9._\-\/]+))?$#', $line, $m)) {
                $owner = $m[1];
                $name = preg_replace('/\.git$/', '', $m[2]);
                $branch = $m[3] ?? 'main';
            } else {
                continue;
            }

            $key = strtolower("{$owner}/{$name}");
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $official = collect(config('egg-browser.default_repositories', []))
                ->contains(fn ($r) => strtolower($r['owner'] . '/' . $r['name']) === $key);

            $repos[] = [
                'owner' => $owner,
                'name' => $name,
                'label' => $label ?? Str::title(str_replace(['-', '_'], ' ', $name)),
                'category' => $name,
                'branch' => $branch ?: 'main',
                'enabled' => $enabled,
                'official' => $official,
            ];
        }

        return $repos;
    }

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>
     */
    protected function parseExtraEnvList(): array
    {
        $value = config('egg-browser.extra_repositories', []);

        if (is_array($value)) {
            $raw = implode("\n", array_map(static function ($item) {
                if (is_string($item) || is_numeric($item)) {
                    return (string) $item;
                }

                return '';
            }, $value));
        } elseif (is_string($value)) {
            $raw = $value;
        } else {
            $raw = '';
        }

        if (trim($raw) === '') {
            return [];
        }

        return $this->parseRepositoryText(str_replace(',', "\n", $raw));
    }

    /**
     * @return list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>|null
     */
    protected function readStoredList(): ?array
    {
        try {
            if (!Schema::hasTable('egg_browser_settings')) {
                return null;
            }

            $row = PluginSetting::query()->where('key', self::SETTINGS_KEY)->first();
            if (!$row || !is_array($row->value) || $row->value === []) {
                return null;
            }

            return array_values(array_map(function (array $repo) {
                return [
                    'owner' => $repo['owner'],
                    'name' => $repo['name'],
                    'label' => $repo['label'] ?? $repo['name'],
                    'category' => $repo['category'] ?? $repo['name'],
                    'branch' => $repo['branch'] ?? 'main',
                    'enabled' => (bool) ($repo['enabled'] ?? true),
                    'official' => (bool) ($repo['official'] ?? false),
                ];
            }, $row->value));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{owner: string, name: string, label: string, category: string, branch: string, enabled: bool, official: bool}>  $repos
     */
    protected function writeStoredList(array $repos): void
    {
        if (!Schema::hasTable('egg_browser_settings')) {
            return;
        }

        PluginSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => array_values($repos)]
        );
    }
}
