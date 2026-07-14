<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Jobs\CheckAllTrackedEggsJob;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggInstallService;
use Community\EggBrowser\Services\EggStatusService;
use Community\EggBrowser\Services\GitHubClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Throwable;

class EggBrowserPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg';

    protected static ?string $slug = 'egg-browser';

    protected static ?int $navigationSort = 3;

    protected string $view = 'egg-browser::filament.egg-browser';

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
        $this->statusOptions = $this->buildStatusOptions();
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
        return (string) __('egg-browser::strings.navigation.group');
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshIndex')
                ->label((string) __('egg-browser::strings.browser.refresh_index'))
                ->icon('tabler-refresh')
                ->color('gray')
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
            Action::make('linkLocal')
                ->label((string) __('egg-browser::strings.browser.link_local'))
                ->icon('tabler-link')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription((string) __('egg-browser::strings.browser.link_local_help'))
                ->action(function (): void {
                    try {
                        $result = app(EggInstallService::class)->linkLocalMatches(checkUpstream: true);
                        $this->reloadCatalog();

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
            Action::make('checkUpdates')
                ->label((string) __('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->action(function (): void {
                    CheckAllTrackedEggsJob::dispatch(refreshIndex: false);

                    Notification::make()
                        ->title((string) __('egg-browser::strings.notifications.check_queued'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function updatedSearch(): void
    {
        $this->search = $this->scalarString($this->search);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterRepository(): void
    {
        $this->filterRepository = $this->scalarString($this->filterRepository);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterCategory(): void
    {
        $this->filterCategory = $this->scalarString($this->filterCategory);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }

    public function updatedFilterStatus(): void
    {
        $this->filterStatus = $this->scalarString($this->filterStatus);
        $this->catalogPage = 1;
        $this->reloadCatalog();
    }
    public function detailUrl(string $key): string
    {
        $encoded = rtrim(strtr(base64_encode($key), '+/', '-_'), '=');

        return EggBrowserDetailPage::getUrl(['key' => $encoded]);
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
