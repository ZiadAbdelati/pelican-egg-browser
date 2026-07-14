<?php

namespace Community\EggBrowser\Observers;

use App\Models\Egg;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\EggInstallService;
use Community\EggBrowser\Services\EggManifestService;
use Illuminate\Support\Facades\Log;

/**
 * Keep Egg Browser tracking rows in sync with panel eggs.
 *
 * - deleted: remove tracking for that egg
 * - created/updated: auto-link when a usable GitHub update_url is present
 */
class EggObserver
{
    public function created(Egg $egg): void
    {
        $this->tryAutoLink($egg);
    }

    public function updated(Egg $egg): void
    {
        // Relink only when identity/source fields change.
        if ($egg->wasChanged(['update_url', 'uuid', 'name'])) {
            $this->tryAutoLink($egg);
        } else {
            $this->syncTrackedMetadata($egg);
        }
    }

    public function deleted(Egg $egg): void
    {
        $query = TrackedEgg::query()->where('egg_id', $egg->id);

        if (filled($egg->uuid)) {
            $query->orWhere('egg_uuid', $egg->uuid);
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            Log::info('[egg-browser] removed tracking rows for deleted egg', [
                'egg_id' => $egg->id,
                'egg_uuid' => $egg->uuid,
                'rows' => $deleted,
            ]);
        }
    }

    protected function tryAutoLink(Egg $egg): void
    {
        $updateUrl = trim((string) ($egg->update_url ?? ''));
        if ($updateUrl === '') {
            return;
        }

        // Already tracked for this egg.
        if (TrackedEgg::query()->where('egg_id', $egg->id)->exists()) {
            $this->syncTrackedMetadata($egg);

            return;
        }

        try {
            /** @var EggManifestService $manifests */
            $manifests = app(EggManifestService::class);
            $parts = $manifests->parseGitHubUrl($updateUrl);
            if ($parts === null) {
                return;
            }

            // Source already tracked for another egg — do not steal it.
            $existing = TrackedEgg::query()
                ->where('source_owner', $parts['owner'])
                ->where('source_repo', $parts['repo'])
                ->where('source_path', $parts['path'])
                ->first();

            if ($existing && (int) $existing->egg_id !== (int) $egg->id) {
                return;
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

            /** @var EggInstallService $install */
            $install = app(EggInstallService::class);

            try {
                $install->linkExisting($egg, $catalogEgg);
            } catch (\Throwable $e) {
                // Upstream may be unreachable; still create a tracking row.
                $install->linkExistingLightweight($egg, $catalogEgg);
                Log::warning('[egg-browser] auto-linked egg without upstream check', [
                    'egg_id' => $egg->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            // Never break egg create/update because tracking failed.
            Log::warning('[egg-browser] auto-link failed', [
                'egg_id' => $egg->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function syncTrackedMetadata(Egg $egg): void
    {
        TrackedEgg::query()
            ->where('egg_id', $egg->id)
            ->update([
                'egg_uuid' => $egg->uuid,
                'egg_name' => $egg->name,
            ]);
    }
}
