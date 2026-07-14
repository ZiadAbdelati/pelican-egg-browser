<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Jobs\CheckTrackedEggJob;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\EggInstallService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Throwable;

class TrackedEggsPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'egg-browser-installed';

    protected static ?int $navigationSort = 4;

    protected string $view = 'egg-browser::filament.tracked-eggs';

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('linkLocal')
                ->label((string) __('egg-browser::strings.installed.link_local'))
                ->icon('tabler-link')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $result = app(EggInstallService::class)->linkLocalMatches(checkUpstream: true);

                        $body = (string) __('egg-browser::strings.notifications.link_stats', [
                            'local_total' => $result['stats']['local_total'] ?? 0,
                            'local_untracked' => $result['stats']['local_untracked'] ?? 0,
                            'catalog_total' => $result['stats']['catalog_total'] ?? 0,
                            'matched' => $result['stats']['matched'] ?? 0,
                        ]);
                        if (!empty($result['errors'])) {
                            $body .= "\n" . implode("\n", array_slice($result['errors'], 0, 5));
                        }

                        Notification::make()
                            ->title((string) __('egg-browser::strings.notifications.link_done', [
                                'linked' => $result['linked'],
                                'checked' => $result['checked'],
                                'skipped' => $result['skipped'],
                            ]))
                            ->body($body)
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
            Action::make('checkAll')
                ->label((string) __('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->action(function (): void {
                    CheckAllTrackedEggsJob::dispatch(refreshIndex: true);
                    Notification::make()
                        ->title((string) __('egg-browser::strings.notifications.check_queued'))
                        ->success()
                        ->send();
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
        CheckTrackedEggJob::dispatchSync($id);

        $tracked = TrackedEgg::query()->find($id);
        Notification::make()
            ->title((string) __('egg-browser::strings.browser.check_success', [
                'status' => $tracked?->statusEnum()->displayName() ?? 'unknown',
            ]))
            ->success()
            ->send();
    }

    public function detailUrl(TrackedEgg $tracked): string
    {
        return EggBrowserDetailPage::getUrl(['key' => $tracked->sourceKey()]);
    }

    public function statusColor(string $status): string
    {
        return EggInstallStatus::tryFrom($status)?->color() ?? 'gray';
    }

    public function statusLabel(string $status): string
    {
        return EggInstallStatus::tryFrom($status)?->displayName() ?? $status;
    }
}
