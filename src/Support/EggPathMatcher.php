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

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $candidates[] = [
                'path' => $path,
                'dir' => $dir,
                'sha' => $node['sha'] ?? null,
                'size' => $node['size'] ?? null,
                'basename' => basename($path),
                'extension' => $ext,
                'is_legacy' => $this->isLegacyFilename($path),
                'is_yaml' => in_array($ext, ['yaml', 'yml'], true),
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
            $byDir[$key] = $this->preferCandidate($existing, $candidate, $preferPelican);
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
                'format' => $candidate['is_yaml'] ? 'yaml' : 'json',
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
     * Prefer modern Pelican YAML over JSON, and non-pterodactyl filenames over legacy twins.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    protected function preferCandidate(array $existing, array $candidate, bool $preferPelican): array
    {
        if ($preferPelican) {
            if ($existing['is_legacy'] && !$candidate['is_legacy']) {
                return $candidate;
            }
            if (!$existing['is_legacy'] && $candidate['is_legacy']) {
                return $existing;
            }
        } else {
            if (!$existing['is_legacy'] && $candidate['is_legacy']) {
                return $candidate;
            }
        }

        // Official repos are migrating to YAML / PLCN exports.
        if (!empty($candidate['is_yaml']) && empty($existing['is_yaml'])) {
            return $candidate;
        }
        if (empty($candidate['is_yaml']) && !empty($existing['is_yaml'])) {
            return $existing;
        }

        if (strlen((string) $candidate['basename']) < strlen((string) $existing['basename'])) {
            return $candidate;
        }

        return $existing;
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
        $base = basename($path);
        $base = preg_replace('/\.(json|ya?ml)$/i', '', $base) ?? $base;
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
