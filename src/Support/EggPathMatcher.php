<?php

namespace Community\EggBrowser\Support;

use Illuminate\Support\Str;

class EggPathMatcher
{
    /**
     * @param  list<array<string, mixed>>  $tree
     * @param  array<string, mixed>  $repoMeta
     * @return list<array<string, mixed>>
     */
    public function extractEggsFromTree(
        array $tree,
        string $owner,
        string $repo,
        string $branch,
        string $treeSha,
        array $repoMeta = [],
    ): array {
        $include = config('egg-browser.discovery.include_patterns', []);
        $exclude = config('egg-browser.discovery.exclude_patterns', []);
        $preferPelican = (bool) config('egg-browser.install.prefer_pelican_json', true);

        $candidates = [];

        foreach ($tree as $node) {
            if (($node['type'] ?? '') !== 'blob') {
                continue;
            }

            $path = (string) ($node['path'] ?? '');
            if ($path === '') {
                continue;
            }

            if (!$this->matchesAny($path, $include)) {
                continue;
            }

            if ($this->matchesAny($path, $exclude)) {
                continue;
            }

            $dir = dirname($path);
            if ($dir === '.') {
                $dir = '';
            }

            $candidates[] = [
                'path' => $path,
                'dir' => $dir,
                'sha' => $node['sha'] ?? null,
                'size' => $node['size'] ?? null,
                'basename' => basename($path),
                'is_legacy' => $this->isLegacyFilename($path),
            ];
        }

        $byDir = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['dir'];
            if (!isset($byDir[$key])) {
                $byDir[$key] = $candidate;
                continue;
            }

            $existing = $byDir[$key];
            if ($preferPelican && $existing['is_legacy'] && !$candidate['is_legacy']) {
                $byDir[$key] = $candidate;
            } elseif ($preferPelican && !$existing['is_legacy'] && $candidate['is_legacy']) {
                // keep existing
            } elseif (!$preferPelican && !$existing['is_legacy'] && $candidate['is_legacy']) {
                $byDir[$key] = $candidate;
            } elseif (strlen($candidate['basename']) < strlen($existing['basename'])) {
                $byDir[$key] = $candidate;
            }
        }

        $eggs = [];
        foreach ($byDir as $candidate) {
            $slug = $this->slugFromPath($candidate['path']);
            $category = $repoMeta['category'] ?? $repo;
            $folderCategory = $this->folderCategory($candidate['dir'], $category);

            $eggs[] = [
                'key' => strtolower("{$owner}/{$repo}:{$candidate['path']}"),
                'owner' => $owner,
                'repo' => $repo,
                'repository' => "{$owner}/{$repo}",
                'branch' => $branch,
                'path' => $candidate['path'],
                'blob_sha' => $candidate['sha'],
                'tree_sha' => $treeSha,
                'size' => $candidate['size'],
                'slug' => $slug,
                'name' => Str::title(str_replace(['-', '_'], ' ', $slug)),
                'description' => null,
                'author' => null,
                'uuid' => null,
                'tags' => array_values(array_filter([
                    $category,
                    $folderCategory !== $category ? $folderCategory : null,
                ])),
                'category' => $folderCategory,
                'repository_label' => $repoMeta['label'] ?? $repo,
                'raw_url' => "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$candidate['path']}",
                'html_url' => "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$candidate['path']}",
            ];
        }

        return array_values($eggs);
    }

    /**
     * @param  list<string>  $patterns
     */
    protected function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function isLegacyFilename(string $path): bool
    {
        return (bool) preg_match('/pterodactyl/i', basename($path));
    }

    protected function slugFromPath(string $path): string
    {
        $base = basename($path, '.json');
        $base = preg_replace('/^(egg-|pelican-egg-|pterodactyl-egg-)/i', '', $base) ?? $base;
        $base = preg_replace('/^egg-pterodactyl-/i', '', $base) ?? $base;

        if ($base === '' || strcasecmp($base, 'egg') === 0) {
            $dir = basename(dirname($path));
            if ($dir && $dir !== '.' && $dir !== '/') {
                return $dir;
            }
        }

        return $base ?: 'unknown';
    }

    protected function folderCategory(string $dir, string $fallback): string
    {
        if ($dir === '' || $dir === '.') {
            return $fallback;
        }

        $parts = explode('/', $dir);

        return $parts[0] ?: $fallback;
    }
}
