<?php

namespace Community\EggBrowser\Services;

use App\Models\Egg;
use Community\EggBrowser\Models\TrackedEgg;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Match panel eggs to upstream catalog entries without installing through this plugin.
 */
class EggMatcherService
{
    /** @var Collection<int, Egg>|null */
    protected ?Collection $localEggs = null;

    /** @var array<string, Egg> */
    protected array $byUuid = [];

    /** @var array<string, list<Egg>> */
    protected array $byName = [];

    /** @var array<string, Egg> */
    protected array $byUpdatePath = [];

    /** @var array<int, true> */
    protected array $alreadyTrackedEggIds = [];

    public function warm(): void
    {
        if ($this->localEggs !== null) {
            return;
        }

        $this->localEggs = Egg::query()
            ->select(['id', 'uuid', 'name', 'update_url', 'author', 'description'])
            ->orderBy('id')
            ->get();

        $this->alreadyTrackedEggIds = TrackedEgg::query()
            ->whereNotNull('egg_id')
            ->pluck('egg_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        foreach ($this->localEggs as $egg) {
            $uuid = strtolower(trim((string) $egg->uuid));
            if ($uuid !== '') {
                $this->byUuid[$uuid] = $egg;
            }

            $nameKey = $this->normalizeName((string) $egg->name);
            if ($nameKey !== '') {
                $this->byName[$nameKey] ??= [];
                $this->byName[$nameKey][] = $egg;
            }

            foreach ($this->pathsFromUpdateUrl((string) ($egg->update_url ?? '')) as $pathKey) {
                $this->byUpdatePath[$pathKey] = $egg;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    public function findLocalEgg(array $catalogEgg, bool $includeTracked = true): ?Egg
    {
        $this->warm();

        $candidates = [];

        $uuid = strtolower(trim((string) ($catalogEgg['uuid'] ?? '')));
        if ($uuid !== '' && isset($this->byUuid[$uuid])) {
            $candidates[] = ['egg' => $this->byUuid[$uuid], 'score' => 100, 'via' => 'uuid'];
        }

        $pathKey = $this->catalogPathKey($catalogEgg);
        if ($pathKey !== '' && isset($this->byUpdatePath[$pathKey])) {
            $candidates[] = ['egg' => $this->byUpdatePath[$pathKey], 'score' => 90, 'via' => 'update_url'];
        }

        // raw_url / html_url may also be stored in update_url with slightly different shape
        foreach (['raw_url', 'html_url'] as $field) {
            $url = (string) ($catalogEgg[$field] ?? '');
            foreach ($this->pathsFromUpdateUrl($url) as $key) {
                if (isset($this->byUpdatePath[$key])) {
                    $candidates[] = ['egg' => $this->byUpdatePath[$key], 'score' => 85, 'via' => $field];
                }
            }
        }

        $nameKey = $this->normalizeName((string) ($catalogEgg['name'] ?? $catalogEgg['slug'] ?? ''));
        if ($nameKey !== '' && isset($this->byName[$nameKey]) && count($this->byName[$nameKey]) === 1) {
            $candidates[] = ['egg' => $this->byName[$nameKey][0], 'score' => 50, 'via' => 'name'];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach ($candidates as $candidate) {
            /** @var Egg $egg */
            $egg = $candidate['egg'];
            if (!$includeTracked && isset($this->alreadyTrackedEggIds[(int) $egg->id])) {
                continue;
            }

            return $egg;
        }

        return null;
    }

    /**
     * @return list<array{egg: Egg, catalog: array<string, mixed>, via: string}>
     */
    public function matchCatalog(array $catalogEggs): array
    {
        $this->warm();

        $matches = [];
        $usedEggIds = [];

        foreach ($catalogEggs as $catalogEgg) {
            if (!is_array($catalogEgg)) {
                continue;
            }

            $egg = $this->findLocalEgg($catalogEgg, includeTracked: true);
            if (!$egg || isset($usedEggIds[$egg->id])) {
                continue;
            }

            // Prefer higher-confidence matches only for auto-link.
            $via = $this->matchVia($egg, $catalogEgg);
            if ($via === null) {
                continue;
            }

            $usedEggIds[$egg->id] = true;
            $matches[] = [
                'egg' => $egg,
                'catalog' => $catalogEgg,
                'via' => $via,
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    public function matchVia(Egg $egg, array $catalogEgg): ?string
    {
        $uuid = strtolower(trim((string) ($catalogEgg['uuid'] ?? '')));
        if ($uuid !== '' && strtolower((string) $egg->uuid) === $uuid) {
            return 'uuid';
        }

        $pathKey = $this->catalogPathKey($catalogEgg);
        foreach ($this->pathsFromUpdateUrl((string) ($egg->update_url ?? '')) as $key) {
            if ($pathKey !== '' && $key === $pathKey) {
                return 'update_url';
            }
        }

        $nameKey = $this->normalizeName((string) ($catalogEgg['name'] ?? $catalogEgg['slug'] ?? ''));
        $eggNameKey = $this->normalizeName((string) $egg->name);
        if (
            $nameKey !== ''
            && $nameKey === $eggNameKey
            && isset($this->byName[$nameKey])
            && count($this->byName[$nameKey]) === 1
        ) {
            return 'name';
        }

        return null;
    }

    /**
     * @return list<Egg>
     */
    public function untrackedLocalEggs(): array
    {
        $this->warm();

        return $this->localEggs
            ->filter(fn (Egg $egg) => !isset($this->alreadyTrackedEggIds[(int) $egg->id]))
            ->values()
            ->all();
    }

    public function forget(): void
    {
        $this->localEggs = null;
        $this->byUuid = [];
        $this->byName = [];
        $this->byUpdatePath = [];
        $this->alreadyTrackedEggIds = [];
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function catalogPathKey(array $catalogEgg): string
    {
        $owner = strtolower(trim((string) ($catalogEgg['owner'] ?? '')));
        $repo = strtolower(trim((string) ($catalogEgg['repo'] ?? '')));
        $path = strtolower(ltrim(trim((string) ($catalogEgg['path'] ?? '')), '/'));

        if ($owner === '' || $repo === '' || $path === '') {
            return '';
        }

        return $owner . '/' . $repo . '/' . $path;
    }

    /**
     * @return list<string>
     */
    protected function pathsFromUpdateUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return [];
        }

        $keys = [];

        // raw.githubusercontent.com/owner/repo/branch/path...
        if (preg_match('#raw\.githubusercontent\.com/([^/]+)/([^/]+)/[^/]+/(.+)$#i', $url, $m)) {
            $keys[] = strtolower($m[1] . '/' . $m[2] . '/' . ltrim($m[3], '/'));
        }

        // github.com/owner/repo/blob/branch/path...
        if (preg_match('#github\.com/([^/]+)/([^/]+)/blob/[^/]+/(.+)$#i', $url, $m)) {
            $keys[] = strtolower($m[1] . '/' . $m[2] . '/' . ltrim($m[3], '/'));
        }

        // github.com/owner/repo/.../path.json (fallback)
        if (preg_match('#github\.com/([^/]+)/([^/]+)/.+?/([^?]+\.json)$#i', $url, $m)) {
            $keys[] = strtolower($m[1] . '/' . $m[2] . '/' . ltrim($m[3], '/'));
        }

        return array_values(array_unique($keys));
    }

    protected function normalizeName(string $name): string
    {
        $name = Str::lower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return $name;
    }
}
