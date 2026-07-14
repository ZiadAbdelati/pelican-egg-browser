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
            try {
                foreach ($this->indexRepository(
                    $repo['owner'],
                    $repo['name'],
                    $repo['branch'] ?? 'main',
                    $repo,
                    $forceRefresh,
                ) as $egg) {
                    $eggs[] = $egg;
                }
            } catch (\Throwable $e) {
                Log::warning('[egg-browser] Failed to index repository', [
                    'repo' => $repo['owner'] . '/' . $repo['name'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        usort($eggs, fn ($a, $b) => strcasecmp($a['name'] ?? $a['slug'], $b['name'] ?? $b['slug']));

        return $eggs;
    }

    /**
     * @param  array{q?: string, repository?: string, category?: string, tag?: string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function search(array $filters = [], bool $forceRefresh = false): Collection
    {
        $eggs = collect($this->allEggs($forceRefresh));

        $q = $this->filterString($filters['q'] ?? null);
        if ($q !== '') {
            $needle = Str::lower($q);
            $eggs = $eggs->filter(function (array $egg) use ($needle) {
                $tags = $egg['tags'] ?? [];
                if (!is_array($tags)) {
                    $tags = [];
                }

                $haystack = Str::lower(implode(' ', array_filter([
                    is_scalar($egg['name'] ?? null) ? (string) $egg['name'] : '',
                    is_scalar($egg['slug'] ?? null) ? (string) $egg['slug'] : '',
                    is_scalar($egg['description'] ?? null) ? (string) $egg['description'] : '',
                    is_scalar($egg['path'] ?? null) ? (string) $egg['path'] : '',
                    is_scalar($egg['repository'] ?? null) ? (string) $egg['repository'] : '',
                    is_scalar($egg['category'] ?? null) ? (string) $egg['category'] : '',
                    implode(' ', array_map(static fn ($tag) => is_scalar($tag) ? (string) $tag : '', $tags)),
                ])));

                return str_contains($haystack, $needle);
            });
        }

        $repo = $this->filterString($filters['repository'] ?? null);
        if ($repo !== '') {
            $eggs = $eggs->filter(fn (array $egg) => strcasecmp((string) ($egg['repository'] ?? ''), $repo) === 0);
        }

        $category = $this->filterString($filters['category'] ?? null);
        if ($category !== '') {
            $eggs = $eggs->filter(fn (array $egg) => strcasecmp((string) ($egg['category'] ?? ''), $category) === 0);
        }

        $tag = $this->filterString($filters['tag'] ?? null);
        if ($tag !== '') {
            $eggs = $eggs->filter(function (array $egg) use ($tag) {
                foreach ($egg['tags'] ?? [] as $eggTag) {
                    if (is_scalar($eggTag) && strcasecmp((string) $eggTag, $tag) === 0) {
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
        $cacheKey = $this->cacheKey($owner, $name, $branch);
        $ttl = (int) config('egg-browser.cache.index_ttl', 3600);

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }

            $row = RepositoryCache::query()
                ->where('owner', $owner)
                ->where('name', $name)
                ->where('branch', $branch)
                ->first();

            if ($row && is_array($row->eggs) && $row->fetched_at && $row->fetched_at->gt(now()->subSeconds($ttl))) {
                Cache::put($cacheKey, $row->eggs, $ttl);

                return $row->eggs;
            }
        }

        try {
            $tree = $this->github->getRecursiveTree($owner, $name, $branch);
            $eggs = $this->pathMatcher->extractEggsFromTree(
                $tree['tree'],
                $owner,
                $name,
                $branch,
                $tree['sha'],
                $repoMeta,
            );
            $eggs = $this->mergePreviousMetadata($owner, $name, $branch, $eggs);

            RepositoryCache::query()->updateOrCreate(
                ['owner' => $owner, 'name' => $name, 'branch' => $branch],
                [
                    'tree_sha' => $tree['sha'],
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
                return $row->eggs;
            }

            throw $e;
        }
    }

    public function findByKey(string $key): ?array
    {
        foreach ($this->allEggs() as $egg) {
            if (($egg['key'] ?? '') === $key) {
                return $egg;
            }
        }

        return null;
    }

    public function forgetCache(?string $owner = null, ?string $name = null, ?string $branch = null): void
    {
        if ($owner && $name) {
            Cache::forget($this->cacheKey($owner, $name, $branch ?? 'main'));

            return;
        }

        foreach ($this->repositories->configuredRepositories() as $repo) {
            Cache::forget($this->cacheKey($repo['owner'], $repo['name'], $repo['branch'] ?? 'main'));
        }
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return collect($this->allEggs())
            ->pluck('category')
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
        return collect($this->repositories->enabledRepositories())
            ->mapWithKeys(fn (array $repo) => [
                $repo['owner'] . '/' . $repo['name'] => ($repo['label'] ?? $repo['name']) . ' (' . $repo['owner'] . '/' . $repo['name'] . ')',
            ])
            ->all();
    }

    protected function cacheKey(string $owner, string $name, string $branch): string
    {
        $prefix = config('egg-browser.cache.prefix', 'egg-browser');

        return "{$prefix}:index:" . strtolower("{$owner}/{$name}@{$branch}");
    }

    protected function filterString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_scalar($first) ? trim((string) $first) : '';
        }

        return '';
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

        $byPath = collect($row->eggs)->keyBy('path');

        return array_map(function (array $egg) use ($byPath) {
            $prev = $byPath->get($egg['path']);
            if (!$prev) {
                return $egg;
            }

            foreach (['name', 'description', 'author', 'uuid', 'tags'] as $field) {
                if (empty($egg[$field]) && !empty($prev[$field])) {
                    $egg[$field] = $prev[$field];
                }
            }

            return $egg;
        }, $eggs);
    }
}
