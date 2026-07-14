<?php

namespace Community\EggBrowser\Console\Commands;

use Community\EggBrowser\Services\TrackedEggRepairService;
use Illuminate\Console\Command;

class RepairTrackedEggsCommand extends Command
{
    protected $signature = 'egg-browser:repair-tracking {--dry-run : Report invalid source links without removing them}';

    protected $description = 'Remove unverified Egg Browser source links; never removes local panel eggs';

    public function handle(TrackedEggRepairService $repair): int
    {
        $result = $repair->repair(dryRun: (bool) $this->option('dry-run'));

        $this->info('Checked: ' . $result['checked']);
        $this->info('Verified/retained: ' . $result['retained']);
        $this->info(($this->option('dry-run') ? 'Would remove: ' : 'Removed: ') . $result['removed']);
        $this->warn('Unresolved: ' . $result['unresolved']);

        foreach (array_slice($result['errors'], 0, 20) as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
