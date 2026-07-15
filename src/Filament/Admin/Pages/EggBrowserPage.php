<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Jobs\CheckTrackedEggJob;
use Community\EggBrowser\Models\TrackedEgg;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggInstallService;
use Community\EggBrowser\Services\EggStatusService;
use Community\EggBrowser\Services\GitHubClient;
use Community\EggBrowser\Services\TrackedEggSyncService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

class EggBrowserPage extends Page implements HasTable
{
    use InteractsWithTable;
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg';

    protected static ?string $slug = 'egg-browser';

    protected static ?int $navigationSort = 2;


    protected string $view = 'egg-browser::filament.egg-browser';

    #[Url]
    public string $activeTab = 'browser';

    public string $search = '';

    public string $filterRepository = '';

    public string $filterCategory = '';

    public string $filterStatus = '';

    public int $catalogPage = 1;

    public int $perPage = 24;

    public string $indexError = '';

    /** @var list<array<string, string>> */
    public array $eggCards = [];

    public int $eggTotal = 0;

    public int $eggTotalPages = 1;

    /** @var array<string, string> */
    public array $repositoryOptions = [];

    /** @var array<string, string> */
    public array $categoryOptions = [];

    /** @var array<string, string> */
    public array $statusOptions = [];

    public string $rateLimitText = 'n/a';

    public function mount(): void
    {
        if (!in_array($this->activeTab, ['browser', 'manage'], true)) {
            $this->activeTab = 'browser';
        }

        $this->statusOptions = $this->buildStatusOptions();
        app(TrackedEggSyncService::class)->pruneOrphans();
        $this->reloadCatalog();
    }

    public function getTitle(): string
    {
        return (string) __('egg-browser::strings.browser.title');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('egg-browser::strings.navigation.browser');
    }

    public static function getNavigationGroup(): ?string
    {
        // Same sidebar group as Pelican's stock Eggs resource, but not nested under it.
        return \App\Filament\Admin\Resources\Eggs\EggResource::getNavigationGroup();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (method_exists($user, 'can') && ($user->can('egg_browser.view') || $user->can('admin.*') || $user->can('egg.*'))) {
            return true;
        }

        return method_exists($user, 'isRootAdmin') ? (bool) $user->isRootAdmin() : true;
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    public function setActiveTab(string $tab): void
    {
        if (!in_array($tab, ['browser', 'manage'], true)) {
            return;
        }

        $this->activeTab = $tab;

        if ($tab === 'manage') {
            app(TrackedEggSyncService::class)->pruneOrphans();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(TrackedEgg::query()->with('egg'))
            ->defaultSort('egg_name')
            ->columns([
                TextColumn::make('egg_name')
                    ->label('Egg')
                    ->description(fn (TrackedEgg $record): string => $record->egg_uuid ?? '')
                    ->icon('tabler-egg')
                    ->searchable(),
                TextColumn::make('source_repo')
                    ->label((string) __('egg-browser::strings.installed.source'))
                    ->description(fn (TrackedEgg $record): string => $record->source_path)
                    ->formatStateUsing(fn (string $state, TrackedEgg $record): string => $record->source_owner . '/' . $state)
                    ->searchable(),
                TextColumn::make('status')
                    ->label((string) __('egg-browser::strings.browser.status'))
                    ->badge()
                    ->color(fn (TrackedEgg $record): string => $this->statusColor($record->status))
                    ->formatStateUsing(fn (string $state): string => $this->statusLabel($state))
                    ->tooltip(fn (TrackedEgg $record): ?string => $record->last_error),
                TextColumn::make('last_checked_at')
                    ->label((string) __('egg-browser::strings.installed.last_checked'))
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (TrackedEgg $record): string => $this->trackedDetailUrl($record))
            ->recordActions([
                Action::make('check')
                    ->label((string) __('egg-browser::strings.installed.check'))
                    ->icon('tabler-cloud-search')
                    ->color('gray')
                    ->action(fn (TrackedEgg $record) => $this->checkOne($record->id)),
                DeleteAction::make('delete')
                    ->label((string) __('egg-browser::strings.installed.delete'))
                    ->modalDescription((string) __('egg-browser::strings.installed.delete_confirm'))
                    ->action(fn (TrackedEgg $record) => $this->deleteEgg($record->id)),
            ]);
    }

    protected function getHeaderActions(): array
    {
        if ($this->activeTab === 'manage') {
            return [
                Action::make('checkAll')
                    ->label((string) __('egg-browser::strings.browser.check_updates'))
                    ->icon('tabler-cloud-search')
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
                Action::make('linkLocal')
                    ->label((string) __('egg-browser::strings.browser.link_local'))
                    ->icon('tabler-link')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription((string) __('egg-browser::strings.browser.link_local_help'))
                    ->action(function (): void {
                        try {
                            app(TrackedEggSyncService::class)->pruneOrphans();
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
            ];
        }

        return [
            Action::make('refreshIndex')
                ->label((string) __('egg-browser::strings.browser.refresh_index'))
                ->icon('tabler-refresh')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(EggIndexService::class)->forgetCache();
                        app(EggIndexService::class)->allEggs(forceRefresh: true);
                        $this->reloadCatalog();

                        Notification::make()
                            ->title((string) __('egg-browser::strings.notifications.index_refreshed'))
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
            Action::make('checkUpdates')
                ->label((string) __('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->action(function (): void {
                    try {
                        CheckAllTrackedEggsJob::dispatchSync(refreshIndex: false);
                        $this->reloadCatalog();

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
        ];
    }

    public function updatedSearch($value = null): void
    {
        $this->search = $this->scalarString($value ?? $this->search);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterRepository($value = null): void
    {
        $this->filterRepository = $this->scalarString($value ?? $this->filterRepository);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterCategory($value = null): void
    {
        $this->filterCategory = $this->scalarString($value ?? $this->filterCategory);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterStatus($value = null): void
    {
        $this->filterStatus = $this->scalarString($value ?? $this->filterStatus);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function gotoPage(int $page): void
    {
        $this->catalogPage = max(1, min($page, max(1, $this->eggTotalPages)));
        $this->reloadCatalog();
    }

    public function detailUrl(string $key): string
    {
        $encoded = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');

        return EggBrowserDetailPage::getUrl(['key' => $encoded]);
    }

    public function trackedDetailUrl(TrackedEgg $tracked): string
    {
        return $this->detailUrl($tracked->sourceKey());
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

    public function deleteEgg(int $trackedId): void
    {
        $tracked = TrackedEgg::query()->find($trackedId);
        if (!$tracked) {
            return;
        }

        try {
            $egg = $tracked->egg_id ? \App\Models\Egg::query()->find($tracked->egg_id) : null;
            if (!$egg && $tracked->egg_uuid) {
                $egg = \App\Models\Egg::query()->where('uuid', $tracked->egg_uuid)->first();
            }

            $name = $egg?->name ?? $tracked->egg_name ?? "#{$trackedId}";

            if ($egg) {
                $egg->delete();
            } else {
                $tracked->delete();
            }

            Notification::make()
                ->title((string) __('egg-browser::strings.installed.delete_success', ['name' => $name]))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title((string) __('egg-browser::strings.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
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
            'success' => 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300',
            'warning' => 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300',
            'danger' => 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300',
            'info' => 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300',
            default => 'inline-flex rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
    }

    public function cardStatusBadgeClass(string $color): string
    {
        return match ($color) {
            'success' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300',
            'warning' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300',
            'danger' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300',
            'info' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300',
            default => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
    }

    public function reloadCatalog(bool $forceRefresh = false): void
    {
        $this->indexError = '';
        $this->search = $this->scalarString($this->search);
        $this->filterRepository = $this->scalarString($this->filterRepository);
        $this->filterCategory = $this->scalarString($this->filterCategory);
        $this->filterStatus = $this->scalarString($this->filterStatus);

        $index = app(EggIndexService::class);
        $statusService = app(EggStatusService::class);

        try {
            $this->repositoryOptions = $this->stringifyOptions($index->repositoryOptions());
            $this->statusOptions = $this->buildStatusOptions();
            $this->rateLimitText = $this->formatRateLimit(app(GitHubClient::class)->rateLimitStatus());

            $eggs = $index->search([
                'q' => $this->search,
                'repository' => $this->filterRepository,
                'category' => $this->filterCategory,
            ], $forceRefresh);

            $this->categoryOptions = $this->stringifyOptions(
                $eggs->pluck('category')
                    ->filter(fn ($c) => is_string($c) && $c !== '')
                    ->unique()
                    ->sort()
                    ->mapWithKeys(fn ($c) => [(string) $c => (string) $c])
                    ->all()
            );

            $cards = $eggs->map(function (array $egg) use ($statusService): array {
                try {
                    $result = $statusService->resolveCatalogEgg($egg, fetchUpstream: false);
                    $status = $result['status'] instanceof EggInstallStatus
                        ? $result['status']
                        : EggInstallStatus::NotInstalled;
                } catch (Throwable) {
                    $status = EggInstallStatus::NotInstalled;
                }

                return $this->toCard($egg, $status);
            });

            if ($this->filterStatus !== '') {
                $cards = $cards->filter(
                    fn (array $card) => ($card['status'] ?? '') === $this->filterStatus
                )->values();
            }

            $this->eggTotal = $cards->count();
            $this->eggTotalPages = max(1, (int) ceil($this->eggTotal / max(1, $this->perPage)));
            $this->catalogPage = max(1, min($this->catalogPage, $this->eggTotalPages));

            $offset = ($this->catalogPage - 1) * $this->perPage;
            $this->eggCards = $cards->slice($offset, $this->perPage)->values()->all();
        } catch (Throwable $e) {
            report($e);
            $this->indexError = $e->getMessage();
            $this->eggCards = [];
            $this->eggTotal = 0;
            $this->eggTotalPages = 1;
            $this->catalogPage = 1;
        }
    }

    /**
     * @param  array<string, mixed>  $egg
     * @return array{
     *   key: string,
     *   name: string,
     *   repository_label: string,
     *   category: string,
     *   description: string,
     *   path: string,
     *   blob_sha: string,
     *   status: string,
     *   status_label: string,
     *   status_color: string
     * }
     */
    protected function toCard(array $egg, EggInstallStatus $status): array
    {
        $description = $egg['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            $description = (string) __('egg-browser::strings.browser.no_description');
        }

        return [
            'key' => $this->scalarString($egg['key'] ?? ''),
            'name' => $this->scalarString($egg['name'] ?? $egg['slug'] ?? 'Egg'),
            'repository_label' => $this->scalarString($egg['repository_label'] ?? $egg['repository'] ?? ''),
            'category' => $this->scalarString($egg['category'] ?? '—'),
            'description' => $description,
            'path' => $this->scalarString($egg['path'] ?? ''),
            'blob_sha' => $this->scalarString($egg['blob_sha'] ?? ''),
            'status' => $status->value,
            'status_label' => $status->displayName(),
            'status_color' => $status->color(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function buildStatusOptions(): array
    {
        $options = [];
        foreach (EggInstallStatus::cases() as $status) {
            $options[$status->value] = $status->displayName();
        }

        return $options;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<string, string>
     */
    protected function stringifyOptions(array $options): array
    {
        $out = [];
        foreach ($options as $key => $label) {
            $out[$this->scalarString($key)] = $this->scalarString($label);
        }

        return $out;
    }

    /**
     * @param  array{rate_limit: ?int, remaining: ?int, reset: ?int, authenticated: bool}  $rate
     */
    protected function formatRateLimit(array $rate): string
    {
        if (!array_key_exists('remaining', $rate) || $rate['remaining'] === null) {
            return 'n/a';
        }

        $auth = !empty($rate['authenticated']) ? 'auth' : 'anon';

        return (string) $rate['remaining'] . '/' . (string) ($rate['rate_limit'] ?? '?') . ' (' . $auth . ')';
    }

    protected function scalarString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_scalar($first) || $first instanceof \BackedEnum
                ? $this->scalarString($first)
                : '';
        }

        return '';
    }
}
