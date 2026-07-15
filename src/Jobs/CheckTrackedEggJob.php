<?php

namespace Community\EggBrowser\Jobs;

use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\EggStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckTrackedEggJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public int $trackedEggId,
    ) {}

    public function handle(EggStatusService $status): void
    {
        $tracked = TrackedEgg::query()->find($this->trackedEggId);
        if (!$tracked) {
            return;
        }

        if ($tracked->checking_disabled_at !== null) {
            return;
        }

        $status->checkTracked($tracked, fetchUpstream: true);
    }
}
