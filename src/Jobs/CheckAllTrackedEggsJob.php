<?php

namespace Community\EggBrowser\Jobs;

use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckAllTrackedEggsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public bool $refreshIndex = true,
    ) {}

    public function handle(EggStatusService $status, EggIndexService $index): void
    {
        if ($this->refreshIndex) {
            try {
                $index->allEggs(forceRefresh: true);
            } catch (\Throwable $e) {
                Log::warning('[egg-browser] index refresh failed during scheduled check', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        TrackedEgg::query()
            ->whereNull('checking_disabled_at')
            ->orderBy('id')
            ->chunkById(50, function ($trackedEggs) use ($status) {
                foreach ($trackedEggs as $tracked) {
                    try {
                        CheckTrackedEggJob::dispatchSync($tracked->id);
                    } catch (\Throwable $e) {
                        Log::warning('[egg-browser] tracked egg check failed', [
                            'tracked_id' => $tracked->id,
                            'error' => $e->getMessage(),
                        ]);

                        $tracked->status = 'check_failed';
                        $tracked->last_error = $e->getMessage();
                        $tracked->last_checked_at = now();
                        $tracked->save();
                    }
                }
            });
    }
}
