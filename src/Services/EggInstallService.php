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
        $content = $this->prepareImportContent($parsed, $catalogEgg);

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

        return DB::transaction(function () use ($content, $existing, $catalogEgg, $parsed, $tags, $manifest) {
            $egg = $this->importer->fromContent($content, EggFormat::JSON, $existing);

            $resolvedTags = $tags ?? $this->resolveTags($catalogEgg, $egg);
            if ($resolvedTags !== []) {
                $egg->tags = array_values(array_unique(array_merge($egg->tags ?? [], $resolvedTags)));
                $egg->save();
            }

            $fingerprint = $this->normalizer->fingerprint($parsed);
            $snapshot = $this->normalizer->normalize($parsed);

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
                    'upstream_fingerprint' => $fingerprint,
                    'installed_fingerprint' => $fingerprint,
                    'installed_snapshot' => $snapshot,
                    'egg_uuid' => $egg->uuid,
                    'egg_name' => $egg->name,
                    'status' => EggInstallStatus::UpToDate->value,
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
     * @param  array<array-key, mixed>  $parsed
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function prepareImportContent(array $parsed, array $catalogEgg): string
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
