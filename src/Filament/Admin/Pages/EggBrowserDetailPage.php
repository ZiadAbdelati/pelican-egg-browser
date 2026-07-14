<?php

namespace Community\EggBrowser\Filament\Admin\Pages;

use Community\EggBrowser\Enums\EggInstallStatus;
use Community\EggBrowser\Exceptions\EggInstallException;
use Community\EggBrowser\Jobs\CheckTrackedEggJob;
use Community\EggBrowser\Services\EggIndexService;
use Community\EggBrowser\Services\EggInstallService;
use Community\EggBrowser\Services\EggManifestService;
use Community\EggBrowser\Services\EggStatusService;
use Community\EggBrowser\Support\EggNormalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EggBrowserDetailPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-egg-cracked';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'egg-browser/{key}';

    protected string $view = 'egg-browser::filament.egg-browser-detail';

    #[Locked]
    public string $key = '';

    /** @var array<string, mixed>|null */
    public ?array $catalogEgg = null;

    /** @var array<array-key, mixed>|null */
    public ?array $manifest = null;

    public ?string $rawJson = null;

    public ?string $error = null;

    public string $statusValue = 'not_installed';

    public ?int $localEggId = null;

    public ?int $trackedId = null;

    /** @var array<string, array{changed: bool, left: mixed, right: mixed}> */
    public array $diff = [];

    /** @var list<string> */
    public array $installTags = [];

    public bool $forceUpdate = false;

    public function mount(string $key): void
    {
        $decoded = base64_decode(strtr($key, '-_', '+/'), true);
        $this->key = $decoded !== false ? $decoded : urldecode($key);
        $this->loadEgg();
    }

    public function getTitle(): string
    {
        return $this->catalogEgg['name'] ?? trans('egg-browser::strings.browser.title');
    }

    public static function canAccess(): bool
    {
        return EggBrowserPage::canAccess();
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('back')
                ->label(trans('egg-browser::strings.browser.back'))
                ->url(EggBrowserPage::getUrl())
                ->color('gray')
                ->icon('tabler-arrow-left'),
            Action::make('github')
                ->label(trans('egg-browser::strings.browser.open_github'))
                ->url(fn () => $this->catalogEgg['html_url'] ?? '#', shouldOpenInNewTab: true)
                ->icon('tabler-brand-github')
                ->color('gray')
                ->visible(fn () => filled($this->catalogEgg['html_url'] ?? null)),
            Action::make('download')
                ->label(trans('egg-browser::strings.browser.download_json'))
                ->icon('tabler-download')
                ->color('gray')
                ->action(fn () => $this->downloadJson())
                ->visible(fn () => filled($this->rawJson)),
        ];

        if ($this->statusValue === EggInstallStatus::NotInstalled->value) {
            $actions[] = Action::make('install')
                ->label(trans('egg-browser::strings.browser.install'))
                ->icon('tabler-download')
                ->color('primary')
                ->form([
                    TagsInput::make('tags')
                        ->label(trans('egg-browser::strings.browser.install_tags'))
                        ->default($this->defaultInstallTags()),
                ])
                ->action(function (array $data) {
                    $this->installEgg($data['tags'] ?? []);
                });
        } else {
            $actions[] = Action::make('check')
                ->label(trans('egg-browser::strings.browser.check_updates'))
                ->icon('tabler-cloud-search')
                ->color('gray')
                ->action(fn () => $this->checkNow());

            $actions[] = Action::make('update')
                ->label(trans('egg-browser::strings.browser.update'))
                ->icon('tabler-arrow-up-circle')
                ->color('warning')
                ->visible(fn () => in_array($this->statusValue, [
                    EggInstallStatus::UpdateAvailable->value,
                    EggInstallStatus::LocalChangesAndUpdate->value,
                    EggInstallStatus::LocalChanges->value,
                    EggInstallStatus::UpToDate->value,
                ], true))
                ->form([
                    Toggle::make('force')
                        ->label(trans('egg-browser::strings.browser.force_update'))
                        ->default(in_array($this->statusValue, [
                            EggInstallStatus::LocalChanges->value,
                            EggInstallStatus::LocalChangesAndUpdate->value,
                        ], true)),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    $this->updateEgg((bool) ($data['force'] ?? false));
                });
        }

        return $actions;
    }

    public function loadEgg(): void
    {
        $this->error = null;
        $this->diff = [];

        $catalog = app(EggIndexService::class)->findByKey($this->key);
        if (!$catalog) {
            // Allow deep-link even if index is stale: parse key owner/repo:path
            if (preg_match('#^([^/]+)/([^:]+):(.+)$#', $this->key, $m)) {
                $catalog = [
                    'key' => $this->key,
                    'owner' => $m[1],
                    'repo' => $m[2],
                    'repository' => $m[1] . '/' . $m[2],
                    'path' => $m[3],
                    'branch' => 'main',
                    'name' => basename($m[3], '.json'),
                    'slug' => basename($m[3], '.json'),
                    'category' => $m[2],
                    'html_url' => "https://github.com/{$m[1]}/{$m[2]}/blob/main/{$m[3]}",
                    'raw_url' => "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/main/{$m[3]}",
                ];
            } else {
                $this->error = 'Egg not found in index: ' . $this->key;

                return;
            }
        }

        $this->catalogEgg = $catalog;

        try {
            $manifest = app(EggManifestService::class)->fetch(
                $catalog['owner'],
                $catalog['repo'],
                $catalog['path'],
                $catalog['branch'] ?? 'main',
            );
            $this->manifest = $manifest['parsed'];
            $this->rawJson = $manifest['content'];

            // Enrich display name/description from manifest
            $this->catalogEgg['name'] = $this->manifest['name'] ?? $this->catalogEgg['name'];
            $this->catalogEgg['description'] = $this->manifest['description'] ?? null;
            $this->catalogEgg['author'] = $this->manifest['author'] ?? null;
            $this->catalogEgg['uuid'] = $this->manifest['uuid'] ?? null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        $result = app(EggStatusService::class)->resolveCatalogEgg($this->catalogEgg, fetchUpstream: true);
        $this->statusValue = $result['status']->value;
        $this->localEggId = $result['local_egg']?->id;
        $this->trackedId = $result['tracked']?->id;

        if ($result['local_egg'] && !empty($result['upstream_parsed'])) {
            $this->diff = app(EggStatusService::class)
                ->diffLocalAgainstUpstream($result['local_egg'], $result['upstream_parsed']);
        } elseif ($result['local_egg'] && $this->manifest) {
            $this->diff = app(EggStatusService::class)
                ->diffLocalAgainstUpstream($result['local_egg'], $this->manifest);
        }

        $this->installTags = $this->defaultInstallTags();
    }

    public function statusEnum(): EggInstallStatus
    {
        return EggInstallStatus::tryFrom($this->statusValue) ?? EggInstallStatus::UnknownUnlinked;
    }

    /**
     * @return list<string>
     */
    protected function defaultInstallTags(): array
    {
        $strategy = (string) config('egg-browser.install.tag_strategy', 'repository');
        $defaults = config('egg-browser.install.default_tags', []);
        if (!is_array($defaults)) {
            $defaults = [];
        }

        $tags = match ($strategy) {
            'custom' => $defaults,
            'ask' => [],
            'category' => array_merge($defaults, [($this->catalogEgg['category'] ?? null)]),
            default => array_merge($defaults, [($this->catalogEgg['repo'] ?? null)]),
        };

        return array_values(array_filter(array_map('strval', $tags)));
    }

    /**
     * @param  list<string>  $tags
     */
    public function installEgg(array $tags = []): void
    {
        if (!$this->catalogEgg) {
            return;
        }

        try {
            $egg = app(EggInstallService::class)->install($this->catalogEgg, tags: $tags ?: null);

            Notification::make()
                ->title(trans('egg-browser::strings.browser.install_success', ['name' => $egg->name]))
                ->success()
                ->send();

            $this->loadEgg();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(trans('egg-browser::strings.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function updateEgg(bool $force = false): void
    {
        if (!$this->catalogEgg) {
            return;
        }

        try {
            $egg = app(EggInstallService::class)->update($this->catalogEgg, force: $force);

            Notification::make()
                ->title(trans('egg-browser::strings.browser.update_success', ['name' => $egg->name]))
                ->success()
                ->send();

            $this->loadEgg();
        } catch (EggInstallException $e) {
            Notification::make()
                ->title(trans('egg-browser::strings.notifications.error'))
                ->body($e->getMessage())
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(trans('egg-browser::strings.notifications.error'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function checkNow(): void
    {
        if ($this->trackedId) {
            CheckTrackedEggJob::dispatchSync($this->trackedId);
        }

        $this->loadEgg();

        Notification::make()
            ->title(trans('egg-browser::strings.browser.check_success', [
                'status' => $this->statusEnum()->displayName(),
            ]))
            ->success()
            ->send();
    }

    public function downloadJson(): ?StreamedResponse
    {
        if (!$this->rawJson || !$this->catalogEgg) {
            return null;
        }

        $filename = basename($this->catalogEgg['path'] ?? 'egg.json');

        return response()->streamDownload(function () {
            echo $this->rawJson;
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        if (!$this->manifest) {
            return [];
        }

        $sections = app(EggNormalizer::class)->sections($this->manifest);

        return [
            'name' => $this->manifest['name'] ?? null,
            'author' => $this->manifest['author'] ?? null,
            'description' => $this->manifest['description'] ?? null,
            'uuid' => $this->manifest['uuid'] ?? null,
            'meta_version' => $this->manifest['meta']['version'] ?? null,
            'docker_images' => $sections['docker_images'] ?? [],
            'startup' => $sections['startup'] ?? [],
            'variables_count' => is_array($sections['variables'] ?? null) ? count($sections['variables']) : 0,
            'features' => $sections['features'] ?? [],
            'tags' => $sections['tags'] ?? [],
            'install_container' => $sections['scripts']['container'] ?? null,
            'config_stop' => is_array($sections['config_files'] ?? null)
                ? ($sections['config_files']['stop'] ?? null)
                : null,
        ];
    }
}
