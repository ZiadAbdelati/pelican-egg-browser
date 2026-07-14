<?php

namespace Community\EggBrowser\Services;

use Community\EggBrowser\Exceptions\GitHubException;
use Illuminate\Support\Facades\Cache;
use JsonException;

class EggManifestService
{
    public function __construct(
        protected GitHubClient $github,
    ) {}

    /**
     * @return array{content: string, parsed: array<array-key, mixed>, fingerprint_source: array<array-key, mixed>}
     */
    public function fetch(
        string $owner,
        string $repo,
        string $path,
        string $branch = 'main',
        bool $forceRefresh = false,
    ): array {
        $cacheKey = $this->cacheKey($owner, $repo, $path, $branch);
        $ttl = (int) config('egg-browser.cache.manifest_ttl', 1800);

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['content'], $cached['parsed'])) {
                return $cached;
            }
        }

        $content = $this->github->getRawFile($owner, $repo, $path, $branch);

        try {
            /** @var array<array-key, mixed> $parsed */
            $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GitHubException(
                "Invalid egg JSON at {$owner}/{$repo}/{$path}: {$e->getMessage()}",
                "{$owner}/{$repo}/{$path}",
            );
        }

        $payload = [
            'content' => $content,
            'parsed' => $parsed,
            'fingerprint_source' => $parsed,
            'fetched_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, $ttl);

        return $payload;
    }

    public function forget(string $owner, string $repo, string $path, string $branch = 'main'): void
    {
        Cache::forget($this->cacheKey($owner, $repo, $path, $branch));
    }

    protected function cacheKey(string $owner, string $repo, string $path, string $branch): string
    {
        $prefix = config('egg-browser.cache.prefix', 'egg-browser');

        return "{$prefix}:manifest:" . md5(strtolower("{$owner}/{$repo}@{$branch}:{$path}"));
    }
}
