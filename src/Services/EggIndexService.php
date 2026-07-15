<?php

namespace Community\EggBrowser\Services;

use Community\EggBrowser\Exceptions\GitHubException;
use Community\EggBrowser\Models\RepositoryCache;
use Community\EggBrowser\Support\EggPathMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EggIndexService
{
    public function __construct(
        protected GitHubClient $github,
        protected RepositoryConfigService $repositories,
        protected EggPathMatcher $pathMatcher,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function allEggs(bool $forceRefresh = false): array
    {
        $eggs = [];

        foreach ($this->repositories->enabledRepositories() as $repo) {
            $owner = $this->asString($repo['owner'] ?? '');
            $name = $this->asString($repo['name'] ?? '');
            $branch = $this->asString($repo['branch'] ?? 'main') ?: 'main';

            if ($owner === '' || $name === '') {
                continue;
            }

            try {
                foreach ($this->indexRepository($owner, $name, $branch, $repo, $forceRefresh) as $egg) {
                    if (!is_array($egg)) {
                        continue;
                    }

                    $eggs[] = $this->normalizeEggRecord($egg);
                }
            } catch (Throwable $e) {
                Log::warning('[egg-browser] Failed to index repository', [
                    'repo' => $owner . '/' . $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        usort($eggs, function (array $a, array $b): int {
            $left = $this->asString($a['name'] ?? $a['slug'] ?? '');
            $right = $this->asString($b['name'] ?? $b['slug'] ?? '');

            return strcasecmp($left, $right);
        });

        return $eggs;
    }

    /**
     * @param  array{q?: string, repository?: string, category?: string, tag?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function search(array $filters = [], bool $forceRefresh = false): Collection
    {
        $eggs = collect($this->allEggs($forceRefresh));

        $q = $this->asString($filters['q'] ?? null);
        if ($q !== '') {
            $needle = Str::lower($q);
            $eggs = $eggs->filter(function (array $egg) use ($needle) {
                $tags = is_array($egg['tags'] ?? null) ? $egg['tags'] : [];
                $tagText = implode(' ', array_map(fn ($tag) => $this->asString($tag), $tags));

                $haystack = Str::lower(implode(' ', array_filter([
                    $this->asString($egg['name'] ?? null),
                    $this->asString($egg['slug'] ?? null),
                    $this->asString($egg['description'] ?? null),
                    $this->asString($egg['path'] ?? null),
                    $this->asString($egg['repository'] ?? null),
                    $this->asString($egg['category'] ?? null),
                    $tagText,
                ])));

                return str_contains($haystack, $needle);
            });
        }

        $repo = $this->asString($filters['repository'] ?? null);
        if ($repo !== '') {
            $eggs = $eggs->filter(
                fn (array $egg) => strcasecmp($this->asString($egg['repository'] ?? null), $repo) === 0
            );
        }

        $category = $this->asString($filters['category'] ?? null);
        if ($category !== '') {
            $eggs = $eggs->filter(
                fn (array $egg) => strcasecmp($this->asString($egg['category'] ?? null), $category) === 0
            );
        }

        $tag = $this->asString($filters['tag'] ?? null);
        if ($tag !== '') {
            $eggs = $eggs->filter(function (array $egg) use ($tag) {
                foreach ((array) ($egg['tags'] ?? []) as $eggTag) {
                    if (strcasecmp($this->asString($eggTag), $tag) === 0) {
                        return true;
                    }
                }

                return false;
            });
        }

        return $eggs->values();
    }

    /**
     * @param  array<string, mixed>  $repoMeta
     * @return list<array<string, mixed>>
     */
    public function indexRepository(
        string $owner,
        string $name,
        string $branch = 'main',
        array $repoMeta = [],
        bool $forceRefresh = false,
    ): array {
        $owner = $this->asString($owner);
        $name = $this->asString($name);
        $branch = $this->asString($branch) ?: 'main';

        $cacheKey = $this->cacheKey($owner, $name, $branch);
        $ttl = (int) config('egg-browser.cache.index_ttl', 3600);

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return array_values(array_map(fn ($egg) => $this->normalizeEggRecord((array) $egg), $cached));
            }

            $row = RepositoryCache::query()
                ->where('owner', $owner)
                ->where('name', $name)
                ->where('branch', $branch)
                ->first();

            if ($row && is_array($row->eggs) && $row->fetched_at && $row->fetched_at->gt(now()->subSeconds($ttl))) {
                $eggs = array_values(array_map(fn ($egg) => $this->normalizeEggRecord((array) $egg), $row->eggs));
                Cache::put($cacheKey, $eggs, $ttl);

                return $eggs;
            }
        }

        try {
            $tree = $this->github->getRecursiveTree($owner, $name, $branch);
            $eggs = $this->pathMatcher->extractEggsFromTree(
                $tree['tree'],
                $owner,
                $name,
                $branch,
                $this->asString($tree['sha'] ?? ''),
                $repoMeta,
            );
            $eggs = $this->mergePreviousMetadata($owner, $name, $branch, $eggs);
            $eggs = array_values(array_map(fn ($egg) => $this->normalizeEggRecord($egg), $eggs));

            RepositoryCache::query()->updateOrCreate(
                ['owner' => $owner, 'name' => $name, 'branch' => $branch],
                [
                    'tree_sha' => $this->asString($tree['sha'] ?? ''),
                    'eggs' => $eggs,
                    'last_error' => null,
                    'fetched_at' => Carbon::now(),
                ]
            );

            Cache::put($cacheKey, $eggs, $ttl);

            return $eggs;
        } catch (GitHubException $e) {
            RepositoryCache::query()->updateOrCreate(
                ['owner' => $owner, 'name' => $name, 'branch' => $branch],
                [
                    'last_error' => $e->getMessage(),
                    'fetched_at' => Carbon::now(),
                ]
            );

            $row = RepositoryCache::query()
                ->where('owner', $owner)
                ->where('name', $name)
                ->where('branch', $branch)
                ->first();

            if ($row && is_array($row->eggs) && $row->eggs !== []) {
                return array_values(array_map(fn ($egg) => $this->normalizeEggRecord((array) $egg), $row->eggs));
            }

            throw $e;
        }
    }

    public function findByKey(string $key): ?array
    {
        $key = Str::lower($this->asString($key));

        foreach ($this->allEggs() as $egg) {
            if (Str::lower($this->asString($egg['key'] ?? '')) === $key) {
                return $egg;
            }
        }

        return null;
    }

    /**
     * Persist manifest metadata discovered on a detail page so cheap list status
     * matching can use UUID/name without fetching every manifest.
     *
     * @param  array<array-key, mixed>  $manifest
     */
    public function rememberManifestMetadata(array $catalogEgg, array $manifest): void
    {
        $owner = $this->asString($catalogEgg['owner'] ?? '');
        $repo = $this->asString($catalogEgg['repo'] ?? '');
        $branch = $this->asString($catalogEgg['branch'] ?? 'main') ?: 'main';
        $path = $this->asString($catalogEgg['path'] ?? '');

        if ($owner === '' || $repo === '' || $path === '') {
            return;
        }

        $row = RepositoryCache::query()
            ->where('owner', $owner)
            ->where('name', $repo)
            ->where('branch', $branch)
            ->first();

        if (!$row || !is_array($row->eggs)) {
            return;
        }

        $changed = false;
        $eggs = array_map(function (array $egg) use ($path, $manifest, &$changed): array {
            if ($this->asString($egg['path'] ?? '') !== $path) {
                return $egg;
            }

            foreach (['name', 'description', 'author', 'uuid'] as $field) {
                $value = $this->asString($manifest[$field] ?? null);
                if ($value !== '' && $this->asString($egg[$field] ?? null) !== $value) {
                    $egg[$field] = $value;
                    $changed = true;
                }
            }

            if (is_array($manifest['tags'] ?? null)) {
                $tags = array_values(array_filter(array_map(fn ($tag) => $this->asString($tag), $manifest['tags'])));
                if ($tags !== [] && ($egg['tags'] ?? []) !== $tags) {
                    $egg['tags'] = $tags;
                    $changed = true;
                }
            }

            return $egg;
        }, $row->eggs);

        if (!$changed) {
            return;
        }

        $normalized = array_values(array_map(fn ($egg) => $this->normalizeEggRecord((array) $egg), $eggs));
        $row->eggs = $normalized;
        $row->save();
        Cache::put($this->cacheKey($owner, $repo, $branch), $normalized, (int) config('egg-browser.cache.index_ttl', 3600));
    }

    public function forgetCache(?string $owner = null, ?string $name = null, ?string $branch = null): void
    {
        if ($owner && $name) {
            Cache::forget($this->cacheKey($owner, $name, $branch ?? 'main'));

            return;
        }

        foreach ($this->repositories->configuredRepositories() as $repo) {
            Cache::forget($this->cacheKey(
                $this->asString($repo['owner'] ?? ''),
                $this->asString($repo['name'] ?? ''),
                $this->asString($repo['branch'] ?? 'main') ?: 'main',
            ));
        }
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return collect($this->allEggs())
            ->map(fn (array $egg) => $this->asString($egg['category'] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function repositoryOptions(): array
    {
        $options = [];

        foreach ($this->repositories->enabledRepositories() as $repo) {
            $owner = $this->asString($repo['owner'] ?? '');
            $name = $this->asString($repo['name'] ?? '');
            if ($owner === '' || $name === '') {
                continue;
            }

            $key = $owner . '/' . $name;
            $label = $this->asString($repo['label'] ?? $name);
            $options[$key] = $label . ' (' . $key . ')';
        }

        return $options;
    }

    protected function cacheKey(string $owner, string $name, string $branch): string
    {
        $prefix = $this->asString(config('egg-browser.cache.prefix', 'egg-browser')) ?: 'egg-browser';

        return $prefix . ':index:' . strtolower($owner . '/' . $name . '@' . $branch);
    }

    /**
     * @param  list<array<string, mixed>>  $eggs
     * @return list<array<string, mixed>>
     */
    protected function mergePreviousMetadata(string $owner, string $name, string $branch, array $eggs): array
    {
        $row = RepositoryCache::query()
            ->where('owner', $owner)
            ->where('name', $name)
            ->where('branch', $branch)
            ->first();

        if (!$row || !is_array($row->eggs)) {
            return $eggs;
        }

        $byPath = collect($row->eggs)->filter(fn ($egg) => is_array($egg))->keyBy(
            fn (array $egg) => $this->asString($egg['path'] ?? '')
        );

        return array_map(function (array $egg) use ($byPath) {
            $prev = $byPath->get($this->asString($egg['path'] ?? ''));
            if (!is_array($prev)) {
                return $egg;
            }

            foreach (['name', 'description', 'author', 'uuid'] as $field) {
                if ($this->asString($egg[$field] ?? null) === '' && $this->asString($prev[$field] ?? null) !== '') {
                    $egg[$field] = $this->asString($prev[$field]);
                }
            }

            if (empty($egg['tags']) && !empty($prev['tags']) && is_array($prev['tags'])) {
                $egg['tags'] = array_values(array_filter(array_map(fn ($tag) => $this->asString($tag), $prev['tags'])));
            }

            return $egg;
        }, $eggs);
    }

    /**
     * @param  array<string, mixed>  $egg
     * @return array<string, mixed>
     */
    protected function normalizeEggRecord(array $egg): array
    {
        $owner = $this->asString($egg['owner'] ?? '');
        $repo = $this->asString($egg['repo'] ?? '');
        $path = $this->asString($egg['path'] ?? '');
        $branch = $this->asString($egg['branch'] ?? 'main') ?: 'main';
        $slug = $this->asString($egg['slug'] ?? basename($path, '.json'));
        $name = $this->asString($egg['name'] ?? null);
        if ($name === '') {
            $name = $slug !== '' ? Str::title(str_replace(['-', '_'], ' ', $slug)) : 'Egg';
        }

        $tags = [];
        if (is_array($egg['tags'] ?? null)) {
            foreach ($egg['tags'] as $tag) {
                $tag = $this->asString($tag);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return [
            'key' => $this->asString($egg['key'] ?? '') ?: strtolower($owner . '/' . $repo . ':' . $path),
            'owner' => $owner,
            'repo' => $repo,
            'repository' => $this->asString($egg['repository'] ?? '') ?: ($owner . '/' . $repo),
            'branch' => $branch,
            'path' => $path,
            'blob_sha' => $this->asString($egg['blob_sha'] ?? null),
            'tree_sha' => $this->asString($egg['tree_sha'] ?? null),
            'size' => is_numeric($egg['size'] ?? null) ? (int) $egg['size'] : null,
            'slug' => $slug,
            'name' => $name,
            'description' => $this->asString($egg['description'] ?? null) ?: null,
            'author' => $this->asString($egg['author'] ?? null) ?: null,
            'uuid' => $this->asString($egg['uuid'] ?? null) ?: null,
            'tags' => array_values(array_unique($tags)),
            'category' => $this->asString($egg['category'] ?? $repo) ?: $repo,
            'repository_label' => $this->asString($egg['repository_label'] ?? $repo) ?: $repo,
            'raw_url' => $this->asString($egg['raw_url'] ?? null)
                ?: "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}",
            'html_url' => $this->asString($egg['html_url'] ?? null)
                ?: "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}",
        ];
    }

    protected function asString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \BackedEnum) {
            return trim((string) $value->value);
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_scalar($first) || $first instanceof \BackedEnum
                ? $this->asString($first)
                : '';
        }

        return '';
    }
}
