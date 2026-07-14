<?php

namespace Community\EggBrowser\Services;

use App\Enums\EggFormat;
use App\Models\Egg;
use App\Services\Eggs\Sharing\EggExporterService;
use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Support\EggNormalizer;
use Illuminate\Support\Facades\Log;
use JsonException;

class EggStatusService
{
    public function __construct(
        protected EggManifestService $manifests,
        protected EggNormalizer $normalizer,
        protected EggExporterService $exporter,
        protected EggMatcherService $matcher,
    ) {}

    /**
     * Resolve install/update status for a catalog egg entry.
     *
     * @param  array<string, mixed>  $catalogEgg
     * @return array{
     *   status: EggInstallStatus,
     *   tracked: ?TrackedEgg,
     *   local_egg: ?Egg,
     *   upstream_fingerprint: ?string,
     *   installed_fingerprint: ?string,
     *   current_fingerprint: ?string,
     *   error: ?string
     * }
     */
    public function resolveCatalogEgg(array $catalogEgg, bool $fetchUpstream = true): array
    {
        $owner = $this->asString($catalogEgg['owner'] ?? null);
        $repo = $this->asString($catalogEgg['repo'] ?? null);
        $path = $this->asString($catalogEgg['path'] ?? null);
        $branch = $this->asString($catalogEgg['branch'] ?? 'main') ?: 'main';

        $tracked = null;
        if ($owner !== '' && $repo !== '' && $path !== '') {
            $tracked = TrackedEgg::query()
                ->where('source_owner', $owner)
                ->where('source_repo', $repo)
                ->where('source_path', $path)
                ->first();
        }

        // List views must stay cheap and never export local eggs.
        if (!$fetchUpstream) {
            if ($tracked) {
                $status = EggInstallStatus::tryFrom((string) $tracked->status) ?? EggInstallStatus::UnknownUnlinked;

                return [
                    'status' => $status,
                    'tracked' => $tracked,
                    'local_egg' => null,
                    'upstream_fingerprint' => $tracked->upstream_fingerprint,
                    'installed_fingerprint' => $tracked->installed_fingerprint,
                    'current_fingerprint' => null,
                    'upstream_parsed' => null,
                    'error' => is_string($tracked->last_error) ? $tracked->last_error : null,
                ];
            }

            // Detect eggs already present in the panel (imported outside this plugin).
            $localEgg = $this->matcher->findLocalEgg($catalogEgg, includeTracked: false);
            if ($localEgg) {
                return [
                    'status' => EggInstallStatus::UnknownUnlinked,
                    'tracked' => null,
                    'local_egg' => $localEgg,
                    'upstream_fingerprint' => null,
                    'installed_fingerprint' => null,
                    'current_fingerprint' => null,
                    'upstream_parsed' => null,
                    'error' => null,
                ];
            }

            return [
                'status' => EggInstallStatus::NotInstalled,
                'tracked' => null,
                'local_egg' => null,
                'upstream_fingerprint' => null,
                'installed_fingerprint' => null,
                'current_fingerprint' => null,
                'upstream_parsed' => null,
                'error' => null,
            ];
        }

        $localEgg = $this->resolveLocalEgg($tracked, $catalogEgg);

        if (!$localEgg && !$tracked) {
            return [
                'status' => EggInstallStatus::NotInstalled,
                'tracked' => null,
                'local_egg' => null,
                'upstream_fingerprint' => null,
                'installed_fingerprint' => null,
                'current_fingerprint' => null,
                'error' => null,
            ];
        }

        if ($localEgg && !$tracked) {
            return [
                'status' => EggInstallStatus::UnknownUnlinked,
                'tracked' => $tracked,
                'local_egg' => $localEgg,
                'upstream_fingerprint' => null,
                'installed_fingerprint' => null,
                'current_fingerprint' => null,
                'error' => null,
            ];
        }

        try {
            $manifest = $this->manifests->fetch(
                $owner,
                $repo,
                $path,
                $branch,
            );
            $upstreamParsed = $manifest['parsed'];
            $upstreamFingerprint = $this->normalizer->fingerprint($upstreamParsed);

            if ($tracked) {
                $tracked->upstream_fingerprint = $upstreamFingerprint;
                $tracked->source_sha = $this->asString($catalogEgg['tree_sha'] ?? $tracked->source_sha);
                $tracked->source_blob_sha = $this->asString($catalogEgg['blob_sha'] ?? $tracked->source_blob_sha);
                $tracked->last_checked_at = now();
                $tracked->last_error = null;
            }

            $installedFingerprint = $tracked?->installed_fingerprint;
            $currentFingerprint = $localEgg ? $this->fingerprintLocalEgg($localEgg) : null;

            $status = $this->computeStatus(
                installed: is_string($installedFingerprint) ? $installedFingerprint : null,
                current: $currentFingerprint,
                upstream: $upstreamFingerprint,
                hasLocal: (bool) $localEgg,
            );

            if ($tracked) {
                $tracked->status = $status->value;
                if ($localEgg) {
                    $tracked->egg_id = $localEgg->id;
                    $tracked->egg_uuid = $localEgg->uuid;
                    $tracked->egg_name = $localEgg->name;
                }

                // Keep the install snapshot honest after external panel updates.
                if (
                    $status === EggInstallStatus::UpToDate
                    && $currentFingerprint
                    && $installedFingerprint
                    && !hash_equals($installedFingerprint, $currentFingerprint)
                ) {
                    $tracked->installed_fingerprint = $currentFingerprint;
                    $tracked->installed_snapshot = $this->normalizer->normalize(
                        $this->exportLocalAsArray($localEgg)
                    );
                }

                $tracked->save();
            }

            return [
                'status' => $status,
                'tracked' => $tracked,
                'local_egg' => $localEgg,
                'upstream_fingerprint' => $upstreamFingerprint,
                'installed_fingerprint' => $installedFingerprint,
                'current_fingerprint' => $currentFingerprint,
                'upstream_parsed' => $upstreamParsed,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[egg-browser] status check failed', [
                'key' => $this->asString($catalogEgg['key'] ?? null),
                'error' => $e->getMessage(),
            ]);

            if ($tracked) {
                $tracked->status = EggInstallStatus::CheckFailed->value;
                $tracked->last_error = $e->getMessage();
                $tracked->last_checked_at = now();
                $tracked->save();
            }

            return [
                'status' => EggInstallStatus::CheckFailed,
                'tracked' => $tracked,
                'local_egg' => $localEgg,
                'upstream_fingerprint' => $tracked?->upstream_fingerprint,
                'installed_fingerprint' => $tracked?->installed_fingerprint,
                'current_fingerprint' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Re-check a tracked egg against upstream.
     */
    public function checkTracked(TrackedEgg $tracked, bool $fetchUpstream = true): EggInstallStatus
    {
        $catalog = [
            'owner' => $tracked->source_owner,
            'repo' => $tracked->source_repo,
            'path' => $tracked->source_path,
            'branch' => $tracked->source_branch,
            'key' => $tracked->sourceKey(),
            'tree_sha' => $tracked->source_sha,
            'blob_sha' => $tracked->source_blob_sha,
        ];

        if (!$fetchUpstream) {
            // Source unavailable path if we cannot reach upstream and have no cached fingerprint.
            if (!$tracked->upstream_fingerprint) {
                $tracked->status = EggInstallStatus::SourceUnavailable->value;
                $tracked->last_checked_at = now();
                $tracked->save();

                return EggInstallStatus::SourceUnavailable;
            }
        }

        $result = $this->resolveCatalogEgg($catalog, $fetchUpstream);

        return $result['status'];
    }

    /**
     * Build a structured section diff between local egg and upstream JSON.
     *
     * @return array<string, array{changed: bool, left: mixed, right: mixed}>
     */
    public function diffLocalAgainstUpstream(Egg $local, array $upstreamParsed): array
    {
        $localParsed = $this->exportLocalAsArray($local);

        return $this->normalizer->diffSections($localParsed, $upstreamParsed);
    }

    public function fingerprintLocalEgg(Egg $egg): string
    {
        return $this->normalizer->fingerprint($this->exportLocalAsArray($egg));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function exportLocalAsArray(Egg $egg): array
    {
        $json = $this->exporter->handle($egg->id, EggFormat::JSON);

        try {
            /** @var array<array-key, mixed> $parsed */
            $parsed = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to export local egg JSON: ' . $e->getMessage(), 0, $e);
        }

        return $parsed;
    }

    protected function computeStatus(
        ?string $installed,
        ?string $current,
        ?string $upstream,
        bool $hasLocal,
    ): EggInstallStatus {
        if (!$hasLocal) {
            return EggInstallStatus::NotInstalled;
        }

        if ($upstream === null) {
            return EggInstallStatus::SourceUnavailable;
        }

        // Live panel content is the source of truth for "do I need an update?"
        $compare = $current ?? $installed;
        if ($compare === null) {
            return EggInstallStatus::UnknownUnlinked;
        }

        $matchesUpstream = hash_equals($compare, $upstream);
        $localDirty = $installed !== null
            && $current !== null
            && !hash_equals($installed, $current);

        // If the live egg already matches upstream, it is up to date even if the
        // install-time snapshot is stale (e.g. updated via the panel egg updater).
        if ($matchesUpstream) {
            return EggInstallStatus::UpToDate;
        }

        if ($localDirty) {
            return EggInstallStatus::LocalChangesAndUpdate;
        }

        return EggInstallStatus::UpdateAvailable;
    }

    /**
     * @param  array<string, mixed>  $catalogEgg
     */
    protected function resolveLocalEgg(?TrackedEgg $tracked, array $catalogEgg): ?Egg
    {
        if ($tracked?->egg_id) {
            $egg = Egg::query()->find($tracked->egg_id);
            if ($egg) {
                return $egg;
            }
        }

        if ($tracked?->egg_uuid) {
            $egg = Egg::query()->where('uuid', $tracked->egg_uuid)->first();
            if ($egg) {
                return $egg;
            }
        }

        $matched = $this->matcher->findLocalEgg($catalogEgg, includeTracked: true);
        if ($matched) {
            return $matched;
        }

        if (!empty($catalogEgg['uuid'])) {
            return Egg::query()->where('uuid', $catalogEgg['uuid'])->first();
        }

        return null;
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
