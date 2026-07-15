<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Jobs\CheckTrackedEggJob;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\TrackedEggSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Throwable;

class TrackedEggsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'egg-browser-installed';

    protected static ?int $navigationSort = 4;

    protected string $view = 'egg-browser::filament.tracked-eggs';

    public function mount(): void
    {
        // Keep list honest even if an egg was deleted outside the observer path.
        app(TrackedEggSyncService::class)->pruneOrphans();
    }

    public function getTitle(): string
    {
        return (string) __('egg-browser::strings.installed.title');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('egg-browser::strings.navigation.installed');
    }

    public static function getNavigationGroup(): ?string
    {
        return (string) __('egg-browser::strings.navigation.group');
    }

    public static function canAccess(): bool
    {
        return EggBrowserPage::canAccess();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function denyUnauthorized(): void
    {
        Notification::make()
            ->title((string) __('egg-browser::strings.notifications.unauthorized'))
            ->danger()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAll')
                ->label((string) __('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->visible(fn (): bool => EggBrowserPage::canManage())
                ->action(function (): void {
                    try {
                        app(TrackedEggSyncService::class)->pruneOrphans();
                        CheckAllTrackedEggsJob::dispatchSync(refreshIndex: false);

                        Notification::make()
                            ->title((string) __('egg-browser::strings.notifications.check_done'))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title((string) __('egg-browser::strings.notifications.error'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('browser')
                ->label((string) __('egg-browser::strings.navigation.browser'))
                ->url(EggBrowserPage::getUrl())
                ->icon('tabler-egg')
                ->color('gray'),
        ];
    }

    /**
     * @return Collection<int, TrackedEgg>
     */
    public function getTrackedProperty(): Collection
    {
        return TrackedEgg::query()
            ->orderByRaw("CASE status
                WHEN 'update_available' THEN 0
                WHEN 'local_changes_and_update' THEN 1
                WHEN 'local_changes' THEN 2
                WHEN 'check_failed' THEN 3
                WHEN 'source_unavailable' THEN 4
                WHEN 'unknown_unlinked' THEN 5
                ELSE 6 END")
            ->orderBy('egg_name')
            ->get();
    }

    public function checkOne(int $id): void
    {
        if (!EggBrowserPage::canManage()) {
            $this->denyUnauthorized();

            return;
        }

        CheckTrackedEggJob::dispatchSync($id);

        $tracked = TrackedEgg::query()->find($id);
        Notification::make()
            ->title((string) __('egg-browser::strings.browser.check_success', [
                'status' => $tracked?->statusEnum()->displayName() ?? 'unknown',
            ]))
            ->success()
            ->send();
    }

    public function toggleChecking(int $id, bool $disabled): void
    {
        if (!EggBrowserPage::canManage()) {
            $this->denyUnauthorized();

            return;
        }

        $tracked = TrackedEgg::query()->find($id);
        if (!$tracked) {
            return;
        }

        $tracked->checking_disabled_at = $disabled ? now() : null;
        $tracked->save();

        Notification::make()
            ->title((string) __('egg-browser::strings.notifications.' . ($disabled ? 'checking_disabled' : 'checking_enabled')))
            ->success()
            ->send();
    }

    public function canCheck(TrackedEgg $tracked): bool
    {
        return $tracked->checking_disabled_at === null;
    }


    public function detailUrl(TrackedEgg $tracked): string
    {
        $encoded = rtrim(strtr(base64_encode($tracked->sourceKey()), '+/', '-_'), '=');

        return EggBrowserDetailPage::getUrl(['key' => $encoded]);
    }

    public function statusColor(string $status): string
    {
        return EggInstallStatus::tryFrom($status)?->color() ?? 'gray';
    }

    public function statusLabel(string $status): string
    {
        return EggInstallStatus::tryFrom($status)?->displayName() ?? $status;
    }

    public function statusBadgeClass(string $status): string
    {
        return match ($this->statusColor($status)) {
            'success' => 'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300',
            'warning' => 'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300',
            'danger' => 'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300',
            'info' => 'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300',
            default => 'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
    }
}
