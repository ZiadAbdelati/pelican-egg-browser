<?php

namespace Community\EggBrowser\Console\Commands;

use Community\EggBrowser\Services\EggInstallService;
use Illuminate\Console\Command;

class LinkLocalEggsCommand extends Command
{
    protected $signature = 'egg-browser:link-local
                            {--no-check : Only create tracking rows; skip upstream compare}';

    protected $description = 'Match existing panel eggs to the Egg Browser catalog and start tracking them';

    public function handle(EggInstallService $install): int
    {
        $this->info('Matching panel eggs to the upstream catalog...');

        try {
            $result = $install->linkLocalMatches(checkUpstream: !(bool) $this->option('no-check'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked: {$result['linked']}");
        $this->info("Upstream-checked: {$result['checked']}");
        $this->info("Skipped (already tracked): {$result['skipped']}");

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
