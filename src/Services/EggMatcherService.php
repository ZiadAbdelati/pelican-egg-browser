<?php

namespace Community\EggBrowser\Services;

use App\Models\Egg;
use Community\EggBrowser\Models\TrackedEgg;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Match panel eggs to upstream catalog entries.
 *
 * Linking walks local eggs first (reverse match). Catalog tree indexes usually
 * lack UUIDs/descriptions, so path/slug/name heuristics matter more than UUID.
 */
class EggMatcherService
{
    /** @var Collection<int, Egg>|null */
    protected ?Collection $localEggs = null;

    /** @var array<int, true> */
    protected array $alreadyTrackedEggIds = [];

    /** @var array<string, true> */
    protected array $alreadyTrackedSources = [];

    public function warm(): void
    {
        if ($this->localEggs !== null) {
            return;
        }

        $this->localEggs = Egg::query()
            ->select(['id', 'uuid', 'name', 'update_url', 'author', 'description'])
            ->orderBy('id')
            ->get();

        $tracked = TrackedEgg::query()
            ->get(['egg_id', 'source_owner', 'source_repo', 'source_path']);

        $this->alreadyTrackedEggIds = $tracked
            ->pluck('egg_id')
            ->filter()
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $this->alreadyTrackedSources = $tracked
            ->mapWithKeys(function (TrackedEgg $row) {
                $key = strtolower(trim($row->source_owner) . '/' . trim($row->source_repo) . ':' . trim($row->source_path));

                return [$key => true];
            })
            ->all();
    }

    /**
     * Find a local panel egg for a catalog entry (browser list badges).
     *
     * @param  array<string, mixed>  $catalogEgg
     */
    public function findLocalEgg(array $catalogEgg, bool $includeTracked = true): ?Egg
    {
        $this->warm();

        $best = null;
        $bestScore = 0;

        foreach ($this->localEggs as $egg) {
            if (!$includeTracked && isset($this->alreadyTrackedEggIds[(int) $egg->id])) {
                continue;
            }

            $score = $this->score($egg, $catalogEgg);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $egg;
            }
        }

        // Browser badges are only safe for a UUID or exact update_url source match.
        return $bestScore >= 95 ? $best : null;
    }

    /**
     * Match every untracked local egg to the best catalog entry.
     *
     * @param  list<array<string, mixed>>  $catalogEggs
     * @return array{
     *   matches: list<array{egg: Egg, catalog: array<string, mixed>, via: string, score: int}>,
     *   stats: array{local_total: int, local_untracked: int, catalog_total: int, matched: int}
     * }
     */
    public function matchCatalog(array $catalogEggs): array
    {
        $this->warm();

        $catalog = array_values(array_filter($catalogEggs, 'is_array'));
        $usedCatalogKeys = [];
        $matches = [];

        $untracked = $this->localEggs
            ->filter(fn (Egg $egg) => !isset($this->alreadyTrackedEggIds[(int) $egg->id]))
            ->values();

        foreach ($untracked as $egg) {
            $best = null;
            $bestScore = 0;
            $bestVia = '';

            foreach ($catalog as $catalogEgg) {
                $key = $this->catalogKey($catalogEgg);
                if ($key === '' || isset($usedCatalogKeys[$key]) || isset($this->alreadyTrackedSources[$key])) {
                    continue;
                }

                $score = $this->score($egg, $catalogEgg);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $catalogEgg;
                    $bestVia = $this->viaFromScore($score, $egg, $catalogEgg);
                }
            }

            // Auto-link only proven source identity: UUID or exact update_url path.
            if ($best !== null && $bestScore >= 95) {
                $key = $this->catalogKey($best);
                $usedCatalogKeys[$key] = true;
                $matches[] = [
                    'egg' => $egg,
                    'catalog' => $best,
                    'via' => $bestVia,
                    'score' => $bestScore,
                ];
            }
        }

        return [
            'matches' => $matches,
            'stats' => [
                'local_total' => $this->localEggs->count(),
                'local_untracked' => $untracked->count(),
                'catalog_total' => count($catalog),
                'matched' => count($matches),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    public function matchVia(Egg $egg, array $catalogEgg): ?string
    {
        $score = $this->score($egg, $catalogEgg);
        if ($score < 95) {
            return null;
        }

        return $this->viaFromScore($score, $egg, $catalogEgg);
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
        $this->alreadyTrackedEggIds = [];
        $this->alreadyTrackedSources = [];
    }

    /**
     * Score how well a local egg matches a catalog entry.
     *
     * @param  array<string, mixed>  $catalogEgg
     */
    public function score(Egg $egg, array $catalogEgg): int
    {
        $score = 0;

        $localUuid = strtolower(trim((string) $egg->uuid));
        $catalogUuid = strtolower(trim((string) ($catalogEgg['uuid'] ?? '')));
        if ($localUuid !== '' && $catalogUuid !== '' && $localUuid === $catalogUuid) {
            $score = max($score, 100);
        }

        $localUpdate = strtolower(trim((string) ($egg->update_url ?? '')));
        if ($localUpdate !== '') {
            $catalogPaths = $this->catalogPathVariants($catalogEgg);
            foreach ($this->pathsFromUpdateUrl($localUpdate) as $pathKey) {
                if (isset($catalogPaths[$pathKey])) {
                    $score = max($score, 95);
                }
            }

            // Loose contains: update_url includes owner/repo/path fragments.
            $owner = strtolower((string) ($catalogEgg['owner'] ?? ''));
            $repo = strtolower((string) ($catalogEgg['repo'] ?? ''));
            $path = strtolower(ltrim((string) ($catalogEgg['path'] ?? ''), '/'));
            if ($owner && $repo && str_contains($localUpdate, $owner . '/' . $repo)) {
                $score = max($score, 70);
                if ($path !== '' && str_contains($localUpdate, $path)) {
                    $score = max($score, 92);
                } elseif ($path !== '' && str_contains($localUpdate, basename($path))) {
                    $score = max($score, 80);
                }
            }
        }

        $localName = $this->normalizeName((string) $egg->name);
        $catalogName = $this->normalizeName((string) ($catalogEgg['name'] ?? ''));
        $catalogSlug = $this->normalizeName((string) ($catalogEgg['slug'] ?? ''));
        $pathSlug = $this->normalizeName($this->slugFromPath((string) ($catalogEgg['path'] ?? '')));

        if ($localName !== '') {
            if ($catalogName !== '' && $localName === $catalogName) {
                $score = max($score, 75);
            }
            if ($catalogSlug !== '' && $localName === $catalogSlug) {
                $score = max($score, 72);
            }
            if ($pathSlug !== '' && $localName === $pathSlug) {
                $score = max($score, 70);
            }

            // Compact compare: "paper spigot" vs "paperspigot", "vanilla minecraft" vs "vanilla"
            $localCompact = $this->compact($localName);
            foreach ([$catalogName, $catalogSlug, $pathSlug] as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $candCompact = $this->compact($candidate);
                if ($candCompact !== '' && $localCompact === $candCompact) {
                    $score = max($score, 68);
                }
                // one contains the other (min length guard)
                if (
                    strlen($localCompact) >= 4
                    && strlen($candCompact) >= 4
                    && (str_contains($localCompact, $candCompact) || str_contains($candCompact, $localCompact))
                ) {
                    $score = max($score, 55);
                }
            }

            // Path folder names: minecraft/java/paper/egg-paper.json → paper
            $path = (string) ($catalogEgg['path'] ?? '');
            foreach (explode('/', $path) as $segment) {
                $seg = $this->normalizeName(preg_replace('/\.json$/i', '', $segment) ?? $segment);
                $seg = $this->normalizeName(preg_replace('/^(egg-|pelican-egg-|pterodactyl-egg-)/i', '', $seg) ?? $seg);
                if ($seg !== '' && ($seg === $localName || $this->compact($seg) === $this->compact($localName))) {
                    $score = max($score, 60);
                }
            }
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function viaFromScore(int $score, Egg $egg, array $catalogEgg): string
    {
        if ($score >= 100) {
            return 'uuid';
        }
        if ($score >= 90) {
            return 'update_url';
        }
        if ($score >= 70) {
            return 'name';
        }
        if ($score >= 55) {
            return 'slug';
        }

        return 'heuristic';
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     * @return array<string, true>
     */
    protected function catalogPathVariants(array $catalogEgg): array
    {
        $out = [];
        $owner = strtolower(trim((string) ($catalogEgg['owner'] ?? '')));
        $repo = strtolower(trim((string) ($catalogEgg['repo'] ?? '')));
        $path = strtolower(ltrim(trim((string) ($catalogEgg['path'] ?? '')), '/'));

        if ($owner && $repo && $path) {
            $out[$owner . '/' . $repo . '/' . $path] = true;
            $out[$owner . '/' . $repo . '/' . basename($path)] = true;
        }

        foreach (['raw_url', 'html_url'] as $field) {
            foreach ($this->pathsFromUpdateUrl((string) ($catalogEgg[$field] ?? '')) as $key) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function catalogKey(array $catalogEgg): string
    {
        $owner = strtolower(trim((string) ($catalogEgg['owner'] ?? '')));
        $repo = strtolower(trim((string) ($catalogEgg['repo'] ?? '')));
        $path = trim((string) ($catalogEgg['path'] ?? ''));

        if ($owner === '' || $repo === '' || $path === '') {
            return '';
        }

        return $owner . '/' . $repo . ':' . $path;
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

        // raw.githubusercontent.com/owner/repo[/refs/heads|/refs/tags]/branch/path
        if (preg_match(
            '#raw\.githubusercontent\.com/([^/]+)/([^/]+)/(?:refs/(?:heads|tags)/)?([^/]+)/(.+)$#i',
            $url,
            $m
        )) {
            $owner = strtolower($m[1]);
            $repo = strtolower($m[2]);
            $path = strtolower(ltrim($m[4], '/'));
            $keys[] = $owner . '/' . $repo . '/' . $path;
            $keys[] = $owner . '/' . $repo . '/' . basename($path);
        }

        // github.com/owner/repo/(blob|raw)[/refs/heads|/refs/tags]/branch/path
        if (preg_match(
            '#github\.com/([^/]+)/([^/]+)/(?:blob|raw)/(?:refs/(?:heads|tags)/)?([^/]+)/(.+)$#i',
            $url,
            $m
        )) {
            $owner = strtolower($m[1]);
            $repo = strtolower($m[2]);
            $path = strtolower(ltrim(preg_replace('/[?#].*$/', '', $m[4]) ?? $m[4], '/'));
            $keys[] = $owner . '/' . $repo . '/' . $path;
            $keys[] = $owner . '/' . $repo . '/' . basename($path);
        }

        // Fallback: any github URL ending with an egg file
        if (preg_match('~github\.com/([^/]+)/([^/]+).+?/([^/?#]+\.(?:json|ya?ml))~i', $url, $m)) {
            $keys[] = strtolower($m[1] . '/' . $m[2] . '/' . $m[3]);
        }

        return array_values(array_unique($keys));
    }

    protected function slugFromPath(string $path): string
    {
        $base = basename($path, '.json');
        $base = preg_replace('/^(egg-|pelican-egg-|pterodactyl-egg-|egg-pterodactyl-)/i', '', $base) ?? $base;

        return $base;
    }

    protected function normalizeName(string $name): string
    {
        $name = Str::lower(trim($name));
        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return $name;
    }

    protected function compact(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower($name)) ?? '';
    }
}
