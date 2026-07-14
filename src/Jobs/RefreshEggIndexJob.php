<?php

namespace Community\EggBrowser\Jobs;

use Community\EggBrowser\Services\EggIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshEggIndexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function handle(EggIndexService $index): void
    {
        $index->forgetCache();
        $index->allEggs(forceRefresh: true);
    }
}
