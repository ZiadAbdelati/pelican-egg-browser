<?php

namespace Community\EggBrowser\Console\Commands;

use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Jobs\CheckTrackedEggJob;
use Community\EggBrowser\Models\TrackedEgg;
use Illuminate\Console\Command;

class CheckEggUpdatesCommand extends Command
{
    protected $signature = 'egg-browser:check-updates
                            {--egg= : Tracked egg ID to check}
                            {--refresh-index : Refresh repository indexes first}
                            {--sync : Run immediately instead of dispatching to the queue}';

    protected $description = 'Check installed/linked eggs for upstream updates';

    public function handle(): int
    {
        $eggId = $this->option('egg');
        $refresh = (bool) $this->option('refresh-index');
        $sync = (bool) $this->option('sync');

        if ($eggId) {
            $tracked = TrackedEgg::query()->find($eggId);
            if (!$tracked) {
                $this->error("Tracked egg #{$eggId} not found.");

                return self::FAILURE;
            }

            if ($sync) {
                CheckTrackedEggJob::dispatchSync((int) $eggId);
                $tracked->refresh();
                $this->info("Status: {$tracked->statusEnum()->displayName()}");
            } else {
                CheckTrackedEggJob::dispatch((int) $eggId);
                $this->info("Dispatched check for tracked egg #{$eggId}.");
            }

            return self::SUCCESS;
        }

        if ($sync) {
            CheckAllTrackedEggsJob::dispatchSync($refresh);
            $this->info('Finished checking all tracked eggs.');
        } else {
            CheckAllTrackedEggsJob::dispatch($refresh);
            $this->info('Dispatched bulk update check job.');
        }

        return self::SUCCESS;
    }
}
