<?php

namespace Community\EggBrowser\Services;

use App\Enums\EggFormat;
use App\Models\Egg;
use App\Services\Eggs\Sharing\EggImporterService;
use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Exceptions\EggInstallException;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Support\EggNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EggInstallService
{
    public function __construct(
        protected EggManifestService $manifests,
        protected EggImporterService $importer,
        protected EggStatusService $status,
        protected EggNormalizer $normalizer,
        protected RepositoryConfigService $repositories,
        protected EggMatcherService $matcher,
        protected EggIndexService $index,
    ) {}

    /**
     * Install an upstream egg into the panel.
     *
     * @param  array<string, mixed>  $catalogEgg
     * @param  list<string>|null  $tags  Override tags; null = use strategy
     */
    public function install(array $catalogEgg, ?array $tags = null, bool $force = false): Egg
    {
        $manifest = $this->manifests->fetch(
            $catalogEgg['owner'],
            $catalogEgg['repo'],
            $catalogEgg['path'],
            $catalogEgg['branch'] ?? 'main',
            forceRefresh: true,
        );

        $parsed = $manifest['parsed'];
        $format = (($manifest['format'] ?? null) === 'yaml') ? EggFormat::YAML : EggFormat::JSON;
        $content = $this->prepareImportContent($parsed, $catalogEgg, $format);

        $existing = null;
        if (!empty($parsed['uuid'])) {
            $existing = Egg::query()->where('uuid', $parsed['uuid'])->first();
        }

        $tracked = TrackedEgg::query()
            ->where('source_owner', $catalogEgg['owner'])
            ->where('source_repo', $catalogEgg['repo'])
            ->where('source_path', $catalogEgg['path'])
            ->first();

        if ($tracked?->egg_id) {
            $existing = Egg::query()->find($tracked->egg_id) ?? $existing;
        }

        if ($existing && !$force) {
            throw EggInstallException::alreadyInstalled($existing->name);
        }

        return DB::transaction(function () use ($content, $existing, $catalogEgg, $parsed, $tags, $format) {
            $egg = $this->importer->fromContent($content, $format, $existing);

            $resolvedTags = $tags ?? $this->resolveTags($catalogEgg, $egg);
            if ($resolvedTags !== []) {
                $egg->tags = array_values(array_unique(array_merge($egg->tags ?? [], $resolvedTags)));
                $egg->save();
            }

            $egg = $egg->refresh();

            // Fingerprint the *live* panel export, not the raw upstream payload.
            // Importer rewrites / tag merges otherwise look like instant local changes.
            $localParsed = $this->status->exportLocalAsArray($egg);
            $installedFingerprint = $this->normalizer->fingerprint($localParsed);
            $installedSnapshot = $this->normalizer->normalize($localParsed);
            $upstreamFingerprint = $this->normalizer->fingerprint($parsed);

            TrackedEgg::query()->updateOrCreate(
                [
                    'source_owner' => $catalogEgg['owner'],
                    'source_repo' => $catalogEgg['repo'],
                    'source_path' => $catalogEgg['path'],
                ],
                [
                    'egg_id' => $egg->id,
                    'source_branch' => $catalogEgg['branch'] ?? 'main',
                    'source_sha' => $catalogEgg['tree_sha'] ?? null,
                    'source_blob_sha' => $catalogEgg['blob_sha'] ?? null,
                    'upstream_fingerprint' => $upstreamFingerprint,
                    'installed_fingerprint' => $installedFingerprint,
                    'installed_snapshot' => $installedSnapshot,
                    'egg_uuid' => $egg->uuid,
                    'egg_name' => $egg->name,
                    'status' => hash_equals($installedFingerprint, $upstreamFingerprint)
                        ? EggInstallStatus::UpToDate->value
                        : EggInstallStatus::LocalChanges->value,
                    'last_error' => null,
                    'last_checked_at' => now(),
                    'last_installed_at' => now(),
                    'last_updated_at' => $existing ? now() : null,
                ]
            );

            Log::info('[egg-browser] egg installed', [
                'egg_id' => $egg->id,
                'source' => $catalogEgg['key'] ?? null,
            ]);

            return $egg->refresh();
        });
    }

    /**
     * Apply an upstream update. Requires force when local changes are present.
     *
     * @param  array<string, mixed>  $catalogEgg
     */
    public function update(array $catalogEgg, bool $force = false): Egg
    {
        $status = $this->status->resolveCatalogEgg($catalogEgg, fetchUpstream: true);

        if ($status['status'] === EggInstallStatus::NotInstalled) {
            return $this->install($catalogEgg);
        }

        if (in_array($status['status'], [
            EggInstallStatus::LocalChanges,
            EggInstallStatus::LocalChangesAndUpdate,
        ], true) && !$force) {
            throw EggInstallException::localChangesRequireForce(
                $status['local_egg']?->name ?? $catalogEgg['name'] ?? 'egg'
            );
        }

        return $this->install($catalogEgg, force: true);
    }

    /**
     * Link an existing panel egg to an upstream source without overwriting content.
     *
     * @param  array<string, mixed>  $catalogEgg
     */
    public function linkExisting(Egg $egg, array $catalogEgg): TrackedEgg
    {
        $manifest = $this->manifests->fetch(
            $catalogEgg['owner'],
            $catalogEgg['repo'],
            $catalogEgg['path'],
            $catalogEgg['branch'] ?? 'main',
        );

        $upstreamFp = $this->normalizer->fingerprint($manifest['parsed']);
        $currentFp = $this->status->fingerprintLocalEgg($egg);

        $status = hash_equals($upstreamFp, $currentFp)
            ? EggInstallStatus::UpToDate
            : EggInstallStatus::UpdateAvailable;

        // Treat current export as the installed snapshot baseline.
        return TrackedEgg::query()->updateOrCreate(
            [
                'source_owner' => $catalogEgg['owner'],
                'source_repo' => $catalogEgg['repo'],
                'source_path' => $catalogEgg['path'],
            ],
            [
                'egg_id' => $egg->id,
                'source_branch' => $catalogEgg['branch'] ?? 'main',
                'source_sha' => $catalogEgg['tree_sha'] ?? null,
                'source_blob_sha' => $catalogEgg['blob_sha'] ?? null,
                'upstream_fingerprint' => $upstreamFp,
                'installed_fingerprint' => $currentFp,
                'installed_snapshot' => $this->normalizer->normalize($this->status->exportLocalAsArray($egg)),
                'egg_uuid' => $egg->uuid,
                'egg_name' => $egg->name,
                'status' => $status->value,
                'last_error' => null,
                'last_checked_at' => now(),
                'last_installed_at' => now(),
            ]
        );
    }

    /**
     * Discover panel eggs that match the catalog and create tracking rows.
     *
     * @return array{
     *   linked: int,
     *   checked: int,
     *   skipped: int,
     *   errors: list<string>,
     *   stats: array{local_total: int, local_untracked: int, catalog_total: int, matched: int}
     * }
     */
    public function linkLocalMatches(bool $checkUpstream = true): array
    {
        $this->matcher->forget();

        $localEggs = Egg::query()
            ->select(['id', 'uuid', 'name', 'update_url', 'author', 'description'])
            ->orderBy('id')
            ->get();

        $trackedEggIds = TrackedEgg::query()
            ->whereNotNull('egg_id')
            ->pluck('egg_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $trackedEggIds = array_fill_keys($trackedEggIds, true);

        $linked = 0;
        $checked = 0;
        $skipped = 0;
        $errors = [];
        $matched = 0;
        $localTotal = $localEggs->count();
        $localUntracked = 0;
        $withUpdateUrl = 0;

        foreach ($localEggs as $egg) {
            if (isset($trackedEggIds[(int) $egg->id])) {
                $skipped++;
                continue;
            }

            $localUntracked++;
            $updateUrl = trim((string) ($egg->update_url ?? ''));
            if ($updateUrl === '') {
                continue;
            }
            $withUpdateUrl++;

            $parts = $this->manifests->parseGitHubUrl($updateUrl);
            if ($parts === null) {
                $errors[] = ($egg->name ?? "egg#{$egg->id}") . ": unsupported update_url ({$updateUrl})";
                continue;
            }

            $catalogEgg = [
                'owner' => $parts['owner'],
                'repo' => $parts['repo'],
                'path' => $parts['path'],
                'branch' => $parts['branch'],
                'raw_url' => $parts['raw_url'],
                'html_url' => $parts['html_url'],
                'key' => strtolower($parts['owner'] . '/' . $parts['repo'] . ':' . $parts['path']),
                'name' => $egg->name,
            ];

            // Avoid double-tracking the same source for a different local egg.
            $existingSource = TrackedEgg::query()
                ->where('source_owner', $catalogEgg['owner'])
                ->where('source_repo', $catalogEgg['repo'])
                ->where('source_path', $catalogEgg['path'])
                ->first();
            if ($existingSource && (int) $existingSource->egg_id !== (int) $egg->id) {
                $errors[] = ($egg->name ?? "egg#{$egg->id}") . ': source already linked to another egg (' . ($existingSource->egg_name ?: "#{$existingSource->egg_id}") . ')';
                continue;
            }

            $matched++;

            try {
                if ($checkUpstream) {
                    try {
                        $this->linkExisting($egg, $catalogEgg);
                        $checked++;
                    } catch (\Throwable $e) {
                        $this->linkExistingLightweight($egg, $catalogEgg);
                        $errors[] = ($egg->name ?? 'egg') . ' linked without upstream check: ' . $e->getMessage();
                    }
                } else {
                    $this->linkExistingLightweight($egg, $catalogEgg);
                }
                $linked++;
            } catch (\Throwable $e) {
                $errors[] = ($egg->name ?? 'egg') . ': ' . $e->getMessage();
            }
        }

        $this->matcher->forget();

        $stats = [
            'local_total' => $localTotal,
            'local_untracked' => $localUntracked,
            'catalog_total' => $withUpdateUrl, // eggs with a usable update_url
            'matched' => $matched,
        ];

        if ($linked === 0 && $localUntracked > 0 && $withUpdateUrl === 0) {
            $errors[] = 'No untracked eggs have an update_url set. Set update_url on each egg to the raw GitHub .yaml/.json file, then link again.';
        } elseif ($linked === 0 && $localUntracked === 0 && $localTotal > 0) {
            $errors[] = 'All local eggs are already tracked.';
        } elseif ($linked === 0 && $withUpdateUrl > 0 && $matched === 0) {
            $errors[] = 'Found update_url values, but none could be parsed as GitHub raw/blob URLs.';
        }

        return [
            'linked' => $linked,
            'checked' => $checked,
            'skipped' => $skipped,
            'errors' => $errors,
            'stats' => $stats,
        ];
    }

    /**
     * Create a tracking row without fetching upstream (status stays unknown_unlinked until checked).
     *
     * @param  array<string, mixed>  $catalogEgg
     */
    public function linkExistingLightweight(Egg $egg, array $catalogEgg): TrackedEgg
    {
        return TrackedEgg::query()->updateOrCreate(
            [
                'source_owner' => $catalogEgg['owner'],
                'source_repo' => $catalogEgg['repo'],
                'source_path' => $catalogEgg['path'],
            ],
            [
                'egg_id' => $egg->id,
                'source_branch' => $catalogEgg['branch'] ?? 'main',
                'source_sha' => $catalogEgg['tree_sha'] ?? null,
                'source_blob_sha' => $catalogEgg['blob_sha'] ?? null,
                'egg_uuid' => $egg->uuid,
                'egg_name' => $egg->name,
                'status' => EggInstallStatus::UnknownUnlinked->value,
                'last_error' => null,
                'last_installed_at' => now(),
            ]
        );
    }

    /**
     * @param  array<array-key, mixed>  $parsed
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function prepareImportContent(array $parsed, array $catalogEgg, EggFormat $format = EggFormat::JSON): string
    {
        if ((bool) config('egg-browser.install.set_update_url', true)) {
            $rawUrl = $catalogEgg['raw_url']
                ?? sprintf(
                    'https://raw.githubusercontent.com/%s/%s/%s/%s',
                    $catalogEgg['owner'],
                    $catalogEgg['repo'],
                    $catalogEgg['branch'] ?? 'main',
                    $catalogEgg['path'],
                );

            $parsed['meta'] = is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [];
            $parsed['meta']['update_url'] = $rawUrl;
        }

        if ($format === EggFormat::YAML) {
            return \Symfony\Component\Yaml\Yaml::dump(
                $parsed,
                8,
                2,
                \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
            );
        }

        return json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     * @return list<string>
     */
    protected function resolveTags(array $catalogEgg, Egg $egg): array
    {
        $strategy = (string) config('egg-browser.install.tag_strategy', 'repository');
        $defaults = config('egg-browser.install.default_tags', []);
        if (!is_array($defaults)) {
            $defaults = [];
        }

        return match ($strategy) {
            'custom' => array_values(array_filter($defaults)),
            'ask' => [],
            'category' => array_values(array_filter(array_merge(
                $defaults,
                [($catalogEgg['category'] ?? $catalogEgg['repo'] ?? null)]
            ))),
            default => array_values(array_filter(array_merge(
                $defaults,
                [($catalogEgg['repo'] ?? null)]
            ))),
        };
    }
}
