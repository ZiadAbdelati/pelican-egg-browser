<?php

namespace Community\EggBrowser\Console\Commands;

use Community\EggBrowser\Services\EggIndexService;
use Illuminate\Console\Command;

class RefreshEggIndexCommand extends Command
{
    protected $signature = 'egg-browser:refresh-index
                            {--sync : Run immediately instead of queueing}';

    protected $description = 'Refresh the Egg Browser upstream repository indexes';

    public function handle(EggIndexService $index): int
    {
        $this->info('Refreshing egg repository indexes...');

        try {
            $index->forgetCache();
            $eggs = $index->allEggs(forceRefresh: true);
            $this->info('Indexed ' . count($eggs) . ' eggs across configured repositories.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
