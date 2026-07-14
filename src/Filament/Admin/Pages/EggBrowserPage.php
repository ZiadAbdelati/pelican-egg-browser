<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Jobs\RefreshEggIndexJob;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggStatusService;
use Community\EggBrowser\Services\GitHubClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

class EggBrowserPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg';

    protected static ?string $slug = 'egg-browser';

    protected static ?int $navigationSort = 3;

    protected string $view = 'egg-browser::filament.egg-browser';

    #[Url]
    public string $q = '';

    #[Url]
    public ?string $repository = null;

    #[Url]
    public ?string $category = null;

    #[Url]
    public ?string $statusFilter = null;

    #[Url]
    public int $page = 1;

    public int $perPage = 24;

    public ?string $indexError = null;

    public function getTitle(): string
    {
        return trans('egg-browser::strings.browser.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('egg-browser::strings.navigation.browser');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('egg-browser::strings.navigation.group');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Fall back to admin-only when custom permissions are not yet granted.
        if (method_exists($user, 'can') && ($user->can('egg_browser.view') || $user->can('admin.*') || $user->can('egg.*'))) {
            return true;
        }

        return method_exists($user, 'isRootAdmin') ? (bool) $user->isRootAdmin() : true;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshIndex')
                ->label(trans('egg-browser::strings.browser.refresh_index'))
                ->icon('tabler-refresh')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function () {
                    try {
                        app(EggIndexService::class)->forgetCache();
                        app(EggIndexService::class)->allEggs(forceRefresh: true);

                        Notification::make()
                            ->title(trans('egg-browser::strings.notifications.index_refreshed'))
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(trans('egg-browser::strings.notifications.error'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('checkUpdates')
                ->label(trans('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->action(function () {
                    CheckAllTrackedEggsJob::dispatch(refreshIndex: false);

                    Notification::make()
                        ->title(trans('egg-browser::strings.notifications.check_queued'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getEggsProperty(): Collection
    {
        $this->indexError = null;

        try {
            $eggs = app(EggIndexService::class)->search([
                'q' => $this->q,
                'repository' => $this->repository,
                'category' => $this->category,
            ]);
        } catch (\Throwable $e) {
            $this->indexError = $e->getMessage();

            return collect();
        }

        $statusService = app(EggStatusService::class);

        $enriched = $eggs->map(function (array $egg) use ($statusService) {
            // Cheap status: use tracked rows without re-fetching every upstream on list view.
            $result = $statusService->resolveCatalogEgg($egg, fetchUpstream: false);
            $egg['install_status'] = $result['status'];
            $egg['tracked'] = $result['tracked'];
            $egg['local_egg_id'] = $result['local_egg']?->id;

            return $egg;
        });

        if ($this->statusFilter) {
            $enriched = $enriched->filter(
                fn (array $egg) => ($egg['install_status']?->value ?? '') === $this->statusFilter
            )->values();
        }

        return $enriched;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getPaginatedEggsProperty(): Collection
    {
        $offset = max(0, ($this->page - 1) * $this->perPage);

        return $this->eggs->slice($offset, $this->perPage)->values();
    }

    public function getTotalPagesProperty(): int
    {
        return max(1, (int) ceil($this->eggs->count() / max(1, $this->perPage)));
    }

    /**
     * @return array<string, string>
     */
    public function getRepositoryOptionsProperty(): array
    {
        return app(EggIndexService::class)->repositoryOptions();
    }

    /**
     * @return array<string, string>
     */
    public function getCategoryOptionsProperty(): array
    {
        $cats = app(EggIndexService::class)->categories();

        return collect($cats)->mapWithKeys(fn ($c) => [$c => $c])->all();
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptionsProperty(): array
    {
        return collect(EggInstallStatus::cases())
            ->mapWithKeys(fn (EggInstallStatus $s) => [$s->value => $s->displayName()])
            ->all();
    }

    /**
     * @return array{rate_limit: ?int, remaining: ?int, reset: ?int, authenticated: bool}
     */
    public function getRateLimitProperty(): array
    {
        return app(GitHubClient::class)->rateLimitStatus();
    }

    public function updatedQ(): void
    {
        $this->page = 1;
    }

    public function updatedRepository(): void
    {
        $this->page = 1;
    }

    public function updatedCategory(): void
    {
        $this->page = 1;
    }

    public function updatedStatusFilter(): void
    {
        $this->page = 1;
    }

    public function gotoPage(int $page): void
    {
        $this->page = max(1, min($page, $this->totalPages));
    }

    public function detailUrl(string $key): string
    {
        return EggBrowserDetailPage::getUrl(['key' => $key]);
    }
}
