<?php

namespace Community\EggBrowser\Support;

use Illuminate\Support\Arr;

/**
 * Normalizes egg JSON for stable fingerprinting and section diffs.
 *
 * Strips volatile fields (export timestamps, panel-generated comments) and
 * canonicalizes nested structures so install-time and live exports compare cleanly.
 */
class EggNormalizer
{
    /**
     * Sections used for structured update diffs.
     *
     * @var list<string>
     */
    public const DIFF_SECTIONS = [
        'metadata',
        'startup',
        'docker_images',
        'variables',
        'scripts',
        'config_files',
        'install_script',
        'features',
        'file_denylist',
        // tags intentionally omitted from status-driving section list
    ];

    /**
     * @param  array<array-key, mixed>  $egg
     * @return array<string, mixed>
     */
    public function normalize(array $egg): array
    {
        $egg = $this->upgradeLegacyShape($egg);

        $normalized = [
            'name' => (string) Arr::get($egg, 'name', ''),
            'author' => (string) Arr::get($egg, 'author', ''),
            'description' => $this->normalizeText(Arr::get($egg, 'description')),
            'uuid' => Arr::get($egg, 'uuid'),
            // Tags are panel/plugin bookkeeping, not upstream content.
            'tags' => $this->normalizeStringList(Arr::get($egg, 'tags', [])),
            'features' => $this->normalizeStringList(Arr::get($egg, 'features', [])),
            'docker_images' => $this->normalizeAssoc(Arr::get($egg, 'docker_images', [])),
            'file_denylist' => $this->normalizeStringList(Arr::get($egg, 'file_denylist', [])),
            'startup_commands' => $this->normalizeStartup(Arr::get($egg, 'startup_commands'), Arr::get($egg, 'startup')),
            'config' => $this->normalizePlaceholders([
                'files' => $this->normalizeJsonish(Arr::get($egg, 'config.files')),
                'startup' => $this->normalizeJsonish(Arr::get($egg, 'config.startup')),
                'logs' => $this->normalizeJsonish(Arr::get($egg, 'config.logs')),
                'stop' => Arr::get($egg, 'config.stop'),
            ]),
            'scripts' => [
                'installation' => [
                    'script' => $this->normalizeText(Arr::get($egg, 'scripts.installation.script')),
                    'container' => Arr::get($egg, 'scripts.installation.container'),
                    'entrypoint' => Arr::get($egg, 'scripts.installation.entrypoint'),
                ],
            ],
            'variables' => $this->normalizePlaceholders($this->normalizeVariables(Arr::get($egg, 'variables', []))),
            // Kept for display diffs only; fingerprint() strips meta/tags.
            'meta' => [
                'version' => Arr::get($egg, 'meta.version'),
                'update_url' => $this->normalizeUpdateUrl(
                    Arr::get($egg, 'meta.update_url') ?? Arr::get($egg, 'update_url')
                ),
            ],
        ];

        return $this->ksortRecursive($normalized);
    }

    /**
     * Content hash for status comparison.
     *
     * Intentionally ignores:
     * - uuid (identity)
     * - tags (plugin/panel labeling)
     * - meta.version / meta.update_url (export format + panel updater fields)
     *
     * @param  array<array-key, mixed>  $egg
     */
    public function fingerprint(array $egg): string
    {
        $normalized = $this->normalize($egg);

        unset($normalized['uuid'], $normalized['tags'], $normalized['meta']);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Extract section payloads for structured diffs.
     *
     * @param  array<array-key, mixed>  $egg
     * @return array<string, mixed>
     */
    public function sections(array $egg): array
    {
        $n = $this->normalize($egg);

        return [
            'metadata' => [
                'name' => $n['name'],
                'author' => $n['author'],
                'description' => $n['description'],
            ],
            'startup' => $n['startup_commands'],
            'docker_images' => $n['docker_images'],
            'variables' => $n['variables'],
            'scripts' => [
                'container' => $n['scripts']['installation']['container'] ?? null,
                'entrypoint' => $n['scripts']['installation']['entrypoint'] ?? null,
            ],
            'config_files' => $n['config'],
            'install_script' => $n['scripts']['installation']['script'] ?? null,
            'features' => $n['features'],
            'file_denylist' => $n['file_denylist'],
            'tags' => $n['tags'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $left
     * @param  array<array-key, mixed>  $right
     * @return array<string, array{changed: bool, left: mixed, right: mixed, fields: list<array{path: string, left: mixed, right: mixed}>}>
     */
    public function diffSections(array $left, array $right): array
    {
        $leftSections = $this->sections($left);
        $rightSections = $this->sections($right);
        $diff = [];

        foreach (self::DIFF_SECTIONS as $section) {
            $l = $leftSections[$section] ?? null;
            $r = $rightSections[$section] ?? null;
            $changed = $this->encode($l) !== $this->encode($r);

            $diff[$section] = [
                'changed' => $changed,
                'left' => $l,
                'right' => $r,
                'fields' => $changed ? $this->flattenDiff($l, $r) : [],
            ];
        }

        return $diff;
    }

    /**
     * Pretty JSON for side-by-side raw comparison.
     *
     * @param  array<array-key, mixed>  $egg
     */
    public function pretty(array $egg): string
    {
        $normalized = $this->normalize($egg);
        // Match fingerprint content so raw compare reflects status-driving fields.
        unset($normalized['uuid'], $normalized['tags'], $normalized['meta']);

        return json_encode(
            $normalized,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return list<array{path: string, left: mixed, right: mixed}>
     */
    protected function flattenDiff(mixed $left, mixed $right, string $prefix = ''): array
    {
        $out = [];

        if (is_array($left) && is_array($right)) {
            $keys = array_values(array_unique(array_merge(array_keys($left), array_keys($right))));
            sort($keys);

            foreach ($keys as $key) {
                $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                $hasLeft = array_key_exists($key, $left);
                $hasRight = array_key_exists($key, $right);

                if ($hasLeft && $hasRight) {
                    if (is_array($left[$key]) && is_array($right[$key])) {
                        $out = array_merge($out, $this->flattenDiff($left[$key], $right[$key], $path));
                    } elseif ($this->encode($left[$key]) !== $this->encode($right[$key])) {
                        $out[] = [
                            'path' => $path,
                            'left' => $left[$key],
                            'right' => $right[$key],
                        ];
                    }
                } elseif ($hasLeft) {
                    $out[] = ['path' => $path, 'left' => $left[$key], 'right' => null];
                } else {
                    $out[] = ['path' => $path, 'left' => null, 'right' => $right[$key]];
                }
            }

            return $out;
        }

        if ($this->encode($left) !== $this->encode($right)) {
            $out[] = [
                'path' => $prefix === '' ? '(root)' : $prefix,
                'left' => $left,
                'right' => $right,
            ];
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $egg
     * @return array<array-key, mixed>
     */
    protected function upgradeLegacyShape(array $egg): array
    {
        // Older eggs use a single "startup" string.
        if (!isset($egg['startup_commands']) && isset($egg['startup']) && is_string($egg['startup'])) {
            $egg['startup_commands'] = ['Default' => $egg['startup']];
        }

        return $egg;
    }

    /**
     * @param  mixed  $startupCommands
     * @param  mixed  $legacyStartup
     * @return array<string, string>
     */
    protected function normalizeStartup(mixed $startupCommands, mixed $legacyStartup): array
    {
        if (is_array($startupCommands) && $startupCommands !== []) {
            $out = [];
            foreach ($startupCommands as $key => $value) {
                if (is_string($value) && $value !== '') {
                    $out[(string) $key] = $value;
                }
            }
            ksort($out);

            return $out;
        }

        if (is_string($legacyStartup) && $legacyStartup !== '') {
            return ['Default' => $legacyStartup];
        }

        return [];
    }

    /**
     * @param  mixed  $variables
     * @return list<array<string, mixed>>
     */
    protected function normalizeVariables(mixed $variables): array
    {
        if (!is_array($variables)) {
            return [];
        }

        $normalized = [];

        foreach ($variables as $variable) {
            if (!is_array($variable)) {
                continue;
            }

            $rules = Arr::get($variable, 'rules', []);
            if (is_string($rules)) {
                $rules = array_values(array_filter(explode('|', $rules)));
            }
            if (!is_array($rules)) {
                $rules = [];
            }

            $normalized[] = [
                'name' => Arr::get($variable, 'name'),
                'description' => $this->normalizeText(Arr::get($variable, 'description')),
                'env_variable' => strtoupper((string) Arr::get($variable, 'env_variable', '')),
                'default_value' => $this->stringifyScalar(Arr::get($variable, 'default_value')),
                'user_viewable' => (bool) Arr::get($variable, 'user_viewable', true),
                'user_editable' => (bool) Arr::get($variable, 'user_editable', true),
                'rules' => array_values($rules),
                'sort' => Arr::get($variable, 'sort'),
            ];
        }

        usort($normalized, fn ($a, $b) => strcmp((string) $a['env_variable'], (string) $b['env_variable']));

        return $normalized;
    }

    protected function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($text);
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Normalize panel export placeholder variants and GitHub URL shape noise.
     */
    protected function normalizeUpdateUrl(mixed $value): ?string
    {
        $url = $this->normalizeNullableString($value);
        if ($url === null) {
            return null;
        }

        // Collapse refs/heads|tags so panel-written and official raw URLs match.
        $url = preg_replace('#/refs/(?:heads|tags)/#i', '/', $url) ?? $url;

        return rtrim($url, '/');
    }

    /**
     * Match Pelican importer upgrades from EggImporterService::UPGRADE_VARIABLES.
     * Treat importer-rewritten variables as equivalent for status fingerprints/diffs.
     *
     * @param  mixed  $value
     * @return mixed
     */
    protected function normalizePlaceholders(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = str_replace('{{server.build.env.', '{{server.environment.', $value);
            $value = str_replace('{{server.build.environment.', '{{server.environment.', $value);

            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->normalizePlaceholders($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    protected function normalizeStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $out = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $out[] = (string) $item;
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeAssoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $out[(string) $key] = (string) $item;
        }
        ksort($out);

        return $out;
    }

    protected function normalizeJsonish(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->ksortRecursive($decoded);
            }

            return $this->normalizeText($value);
        }

        if (is_array($value)) {
            return $this->ksortRecursive($value);
        }

        return $value;
    }

    protected function stringifyScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    protected function ksortRecursive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        // Preserve list order; sort associative keys only.
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->ksortRecursive($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->ksortRecursive($item);
        }

        return $value;
    }

    protected function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
