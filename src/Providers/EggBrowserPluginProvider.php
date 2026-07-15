<?php

namespace Community\EggBrowser\Providers;

use App\Models\Egg;
use App\Models\Role;
use Community\EggBrowser\Console\Commands\CheckEggUpdatesCommand;
use Community\EggBrowser\Console\Commands\LinkLocalEggsCommand;
use Community\EggBrowser\Console\Commands\RefreshEggIndexCommand;
use Community\EggBrowser\Console\Commands\RepairTrackedEggsCommand;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Observers\EggObserver;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggInstallService;
use Community\EggBrowser\Services\EggManifestService;
use Community\EggBrowser\Services\EggMatcherService;
use Community\EggBrowser\Services\EggStatusService;
use Community\EggBrowser\Services\GitHubClient;
use Community\EggBrowser\Services\RepositoryConfigService;
use Community\EggBrowser\Services\TrackedEggRepairService;
use Community\EggBrowser\Services\TrackedEggSyncService;
use Community\EggBrowser\Support\EggNormalizer;
use Community\EggBrowser\Support\EggPathMatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class EggBrowserPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Role::registerCustomPermissions([
            'egg_browser' => [
                'view',
                'install',
                'update',
                'manage',
            ],
        ]);
        Role::registerCustomModelIcon('egg_browser', 'tabler-egg');

        $this->app->singleton(GitHubClient::class);
        $this->app->singleton(RepositoryConfigService::class);
        $this->app->singleton(EggPathMatcher::class);
        $this->app->singleton(EggNormalizer::class);
        $this->app->singleton(EggMatcherService::class);
        $this->app->singleton(EggIndexService::class);
        $this->app->singleton(EggManifestService::class);
        $this->app->singleton(EggStatusService::class);
        $this->app->singleton(EggInstallService::class);
        $this->app->singleton(TrackedEggRepairService::class);
        $this->app->singleton(TrackedEggSyncService::class);
    }

    public function boot(): void
    {
        Egg::observe(EggObserver::class);
        $this->app->booted(function () {
            app(TrackedEggSyncService::class)->autoLinkOnceAfterInstall();
        });


        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshEggIndexCommand::class,
                CheckEggUpdatesCommand::class,
                LinkLocalEggsCommand::class,
                RepairTrackedEggsCommand::class,
            ]);
        }

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            if (!(bool) config('egg-browser.schedule.enabled', true)) {
                return;
            }

            $cron = (string) config('egg-browser.schedule.cron', '0 3 * * *');

            $schedule->job(new CheckAllTrackedEggsJob(
                refreshIndex: (bool) config('egg-browser.schedule.refresh_index_before_check', true),
            ))
                ->cron($cron)
                ->name('egg-browser:check-updates')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }
}
