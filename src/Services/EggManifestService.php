<?php

namespace Community\EggBrowser\Services;

use Community\EggBrowser\Exceptions\GitHubException;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Symfony\Component\Yaml\Yaml;

class EggManifestService
{
    public function __construct(
        protected GitHubClient $github,
    ) {}

    /**
     * @return array{content: string, parsed: array<array-key, mixed>, fingerprint_source: array<array-key, mixed>, format: string}
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
        $format = $this->detectFormat($path, $content);
        $parsed = $this->parse($content, $format, "{$owner}/{$repo}/{$path}");

        $payload = [
            'content' => $content,
            'parsed' => $parsed,
            'fingerprint_source' => $parsed,
            'format' => $format,
            'fetched_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $payload, $ttl);

        return $payload;
    }

    /**
     * Fetch and parse an egg from a full raw/blob URL.
     *
     * @return array{
     *   content: string,
     *   parsed: array<array-key, mixed>,
     *   fingerprint_source: array<array-key, mixed>,
     *   format: string,
     *   owner: string,
     *   repo: string,
     *   branch: string,
     *   path: string,
     *   raw_url: string,
     *   html_url: string
     * }
     */
    public function fetchFromUrl(string $url, bool $forceRefresh = false): array
    {
        $parts = $this->parseGitHubUrl($url);
        if ($parts === null) {
            throw new GitHubException("Unsupported egg update URL: {$url}", $url);
        }

        $manifest = $this->fetch(
            $parts['owner'],
            $parts['repo'],
            $parts['path'],
            $parts['branch'],
            $forceRefresh,
        );

        return array_merge($manifest, $parts);
    }

    /**
     * @return array{owner: string, repo: string, branch: string, path: string, raw_url: string, html_url: string}|null
     */
    public function parseGitHubUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // raw.githubusercontent.com/owner/repo[/refs/heads|/refs/tags]/branch/path
        if (preg_match(
            '#^https?://raw\.githubusercontent\.com/([^/]+)/([^/]+)/(?:refs/(?:heads|tags)/)?([^/]+)/(.+)$#i',
            $url,
            $m
        )) {
            $owner = $m[1];
            $repo = $m[2];
            $branch = $m[3];
            $path = ltrim($m[4], '/');

            return [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
                'path' => $path,
                'raw_url' => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}",
                'html_url' => "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}",
            ];
        }

        // github.com/owner/repo/(blob|raw)[/refs/heads|/refs/tags]/branch/path
        if (preg_match(
            '#^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(?:blob|raw)/(?:refs/(?:heads|tags)/)?([^/]+)/(.+)$#i',
            $url,
            $m
        )) {
            $owner = $m[1];
            $repo = $m[2];
            $branch = $m[3];
            $path = ltrim(preg_replace('/[?#].*$/', '', $m[4]) ?? $m[4], '/');

            return [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branch,
                'path' => $path,
                'raw_url' => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$path}",
                'html_url' => "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}",
            ];
        }

        return null;
    }

    public function forget(string $owner, string $repo, string $path, string $branch = 'main'): void
    {
        Cache::forget($this->cacheKey($owner, $repo, $path, $branch));
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function parse(string $content, string $format, string $context): array
    {
        try {
            if ($format === 'yaml') {
                $parsed = Yaml::parse($content);
            } else {
                $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (JsonException $e) {
            throw new GitHubException("Invalid egg JSON at {$context}: {$e->getMessage()}", $context);
        } catch (\Throwable $e) {
            throw new GitHubException("Invalid egg YAML at {$context}: {$e->getMessage()}", $context);
        }

        if (!is_array($parsed)) {
            throw new GitHubException("Egg manifest at {$context} did not parse to an object/array", $context);
        }

        return $parsed;
    }

    protected function detectFormat(string $path, string $content): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['yaml', 'yml'], true)) {
            return 'yaml';
        }
        if ($ext === 'json') {
            return 'json';
        }

        $trim = ltrim($content);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            return 'json';
        }

        return 'yaml';
    }

    protected function cacheKey(string $owner, string $repo, string $path, string $branch): string
    {
        $prefix = config('egg-browser.cache.prefix', 'egg-browser');

        return "{$prefix}:manifest:" . md5(strtolower("{$owner}/{$repo}@{$branch}:{$path}"));
    }
}
