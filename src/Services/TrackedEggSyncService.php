<?php

namespace Community\EggBrowser\Services;

use App\Models\Egg;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Models\PluginSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class TrackedEggSyncService
{
    /**
     * Drop tracking rows whose local egg no longer exists.
     *
     * @return int Number of rows removed
     */
    public function pruneOrphans(): int
    {
        $removed = 0;

        TrackedEgg::query()
            ->whereNotNull('egg_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$removed) {
                $ids = $rows->pluck('egg_id')->filter()->unique()->values();
                $existing = Egg::query()
                    ->whereIn('id', $ids)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $existing = array_fill_keys($existing, true);

                foreach ($rows as $tracked) {
                    if (!isset($existing[(int) $tracked->egg_id])) {
                        $tracked->delete();
                        $removed++;
                    }
                }
            });

        if ($removed > 0) {
            Log::info('[egg-browser] pruned orphaned tracking rows', ['count' => $removed]);
        }

        return $removed;
    }

    /**
     * Auto-link any untracked local eggs that already have a usable update_url.
     *
     * @return array{linked: int, checked: int, skipped: int, errors: list<string>, stats: array<string, int>}
     */
    public function autoLinkFromUpdateUrls(bool $checkUpstream = true): array
    {
        return app(EggInstallService::class)->linkLocalMatches(checkUpstream: $checkUpstream);
    }

    /**
     * Import existing panel eggs once after the plugin tables are available.
     */
    public function autoLinkOnceAfterInstall(): void
    {
        try {
            if (!Schema::hasTable('egg_browser_settings') || !Schema::hasTable('egg_browser_tracked_eggs')) {
                return;
            }

            $setting = PluginSetting::query()->firstOrCreate(
                ['key' => 'initial_local_link_completed'],
                ['value' => ['completed' => false]]
            );

            if (($setting->value['completed'] ?? false) === true) {
                return;
            }

            $this->pruneOrphans();
            $result = $this->autoLinkFromUpdateUrls(checkUpstream: false);

            $setting->value = [
                'completed' => true,
                'linked' => $result['linked'],
                'checked' => $result['checked'],
                'skipped' => $result['skipped'],
                'errors' => array_slice($result['errors'], 0, 10),
                'ran_at' => now()->toIso8601String(),
            ];
            $setting->save();
        } catch (\Throwable $e) {
            Log::warning('[egg-browser] initial local egg link failed', ['error' => $e->getMessage()]);
        }
    }
}
