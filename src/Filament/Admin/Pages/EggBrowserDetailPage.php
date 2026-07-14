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

    /** @var array<string, array{changed: bool, left: mixed, right: mixed, fields?: list<array{path: string, left: mixed, right: mixed}>}> */
    public array $diff = [];

    public string $localPrettyJson = '';

    public string $upstreamPrettyJson = '';

    public string $unifiedDiff = '';

    /** @var list<array{tag: string, text: string, class: string}> */
    public array $unifiedDiffRows = [];

    public bool $hasLocalChanges = false;

    public bool $hasUpstreamDiff = false;

    /** @var list<string> */
    public array $installTags = [];

    public bool $forceUpdate = false;

    public function mount(string $key): void
    {
        $base64 = strtr($key, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
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
        $this->localPrettyJson = '';
        $this->upstreamPrettyJson = '';
        $this->unifiedDiff = '';
        $this->unifiedDiffRows = [];
        $this->hasLocalChanges = false;
        $this->hasUpstreamDiff = false;

        $catalog = app(EggIndexService::class)->findByKey($this->key);
        if (!$catalog) {
            // Allow deep-link even if index is stale: parse key owner/repo:path
            if (preg_match('#^([^/]+)/([^:]+):(.+)$#', $this->key, $m)) {
                $path = $m[3];
                $catalog = [
                    'key' => $this->key,
                    'owner' => $m[1],
                    'repo' => $m[2],
                    'repository' => $m[1] . '/' . $m[2],
                    'path' => $path,
                    'branch' => 'main',
                    'name' => basename(preg_replace('/\.(json|ya?ml)$/i', '', $path) ?? $path),
                    'slug' => basename(preg_replace('/\.(json|ya?ml)$/i', '', $path) ?? $path),
                    'category' => $m[2],
                    'description' => null,
                    'author' => null,
                    'uuid' => null,
                    'html_url' => "https://github.com/{$m[1]}/{$m[2]}/blob/main/{$path}",
                    'raw_url' => "https://raw.githubusercontent.com/{$m[1]}/{$m[2]}/main/{$path}",
                ];
            } else {
                $this->error = 'Egg not found in index: ' . $this->key;
                $this->catalogEgg = null;

                return;
            }
        }

        // Guarantee scalar keys used by the Blade view.
        $this->catalogEgg = array_merge([
            'name' => null,
            'slug' => null,
            'repository' => null,
            'category' => null,
            'author' => null,
            'description' => null,
            'path' => null,
            'blob_sha' => null,
            'uuid' => null,
            'html_url' => null,
            'raw_url' => null,
            'owner' => null,
            'repo' => null,
            'branch' => 'main',
        ], $catalog);

        $statusService = app(EggStatusService::class);
        $normalizer = app(EggNormalizer::class);

        try {
            $manifest = app(EggManifestService::class)->fetch(
                (string) $this->catalogEgg['owner'],
                (string) $this->catalogEgg['repo'],
                (string) $this->catalogEgg['path'],
                (string) ($this->catalogEgg['branch'] ?? 'main'),
            );
            $this->manifest = $manifest['parsed'];
            $this->rawJson = $manifest['content'];
            $this->upstreamPrettyJson = $normalizer->pretty($this->manifest);

            $this->catalogEgg['name'] = $this->manifest['name'] ?? $this->catalogEgg['name'];
            $this->catalogEgg['description'] = $this->normalizeDescription(
                $this->manifest['description'] ?? $this->catalogEgg['description'] ?? null
            );
            $this->catalogEgg['author'] = $this->manifest['author'] ?? $this->catalogEgg['author'];
            $this->catalogEgg['uuid'] = $this->manifest['uuid'] ?? $this->catalogEgg['uuid'];
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->manifest = null;
            $this->rawJson = null;
            $this->upstreamPrettyJson = '';
            // Keep page usable for 404 / moved sources using tracked/local data.
            $this->catalogEgg['description'] = $this->normalizeDescription($this->catalogEgg['description'] ?? null);
        }

        try {
            $result = $statusService->resolveCatalogEgg($this->catalogEgg, fetchUpstream: $this->error === null);
            $this->statusValue = $result['status']->value;
            $this->localEggId = $result['local_egg']?->id;
            $this->trackedId = $result['tracked']?->id;

            if ($this->error !== null && $result['status'] === EggInstallStatus::CheckFailed) {
                // already failed
            } elseif ($this->error !== null) {
                $this->statusValue = EggInstallStatus::CheckFailed->value;
            }

            $upstreamParsed = $result['upstream_parsed'] ?? $this->manifest;
            if ($result['local_egg'] && is_array($upstreamParsed)) {
                $localParsed = $statusService->exportLocalAsArray($result['local_egg']);
                $this->localPrettyJson = $normalizer->pretty($localParsed);
                $this->diff = $normalizer->diffSections($localParsed, $upstreamParsed);
                $this->hasUpstreamDiff = collect($this->diff)->contains(fn ($info) => !empty($info['changed']));

                if ($this->upstreamPrettyJson === '') {
                    $this->upstreamPrettyJson = $normalizer->pretty($upstreamParsed);
                }

                if ($this->localPrettyJson !== '' && $this->upstreamPrettyJson !== '') {
                    $diffHelper = app(\Community\EggBrowser\Support\TextDiff::class);
                    $rows = $diffHelper->unifiedLines($this->localPrettyJson, $this->upstreamPrettyJson);
                    $this->unifiedDiffRows = array_map(static function (array $row): array {
                        $tag = $row['tag'] ?? 'equal';
                        $text = (string) ($row['text'] ?? '');
                        $prefix = match ($tag) {
                            'add' => '+ ',
                            'remove' => '- ',
                            default => '  ',
                        };

                        return [
                            'tag' => $tag,
                            'text' => $text,
                            'line' => $prefix . $text,
                            'class' => match ($tag) {
                                'add' => 'bg-emerald-950/60 text-emerald-200',
                                'remove' => 'bg-rose-950/60 text-rose-200',
                                default => 'text-gray-300',
                            },
                        ];
                    }, $rows);
                    $this->unifiedDiff = $diffHelper->unifiedText($this->localPrettyJson, $this->upstreamPrettyJson);
                }

                $installed = $result['installed_fingerprint'] ?? null;
                $current = $result['current_fingerprint'] ?? null;
                $this->hasLocalChanges = is_string($installed)
                    && is_string($current)
                    && !hash_equals($installed, $current);
            } elseif ($result['local_egg']) {
                // Upstream missing (404) but local exists — still show local export.
                $localParsed = $statusService->exportLocalAsArray($result['local_egg']);
                $this->localPrettyJson = $normalizer->pretty($localParsed);
                if ($this->catalogEgg['description'] === null) {
                    $this->catalogEgg['description'] = $this->normalizeDescription($localParsed['description'] ?? null);
                }
            }
        } catch (\Throwable $e) {
            $this->error = $this->error ?: $e->getMessage();
            $this->statusValue = EggInstallStatus::CheckFailed->value;
        }

        $this->catalogEgg['description'] = $this->normalizeDescription($this->catalogEgg['description'] ?? null);
        $this->installTags = $this->defaultInstallTags();
    }

    public function statusEnum(): EggInstallStatus
    {
        return EggInstallStatus::tryFrom($this->statusValue) ?? EggInstallStatus::UnknownUnlinked;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->statusEnum()->color()) {
            'success' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300',
            'warning' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300',
            'danger' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300',
            'info' => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300',
            default => 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
        };
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

    public function formatDiffValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            $text = (string) $value;

            return $text === '' ? '""' : $text;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[complex]';
    }

    protected function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        // Strip accidental leading indentation on the first line (common in YAML eggs).
        $text = preg_replace('/^[ \t]+/m', '', $text) ?? $text;

        return trim($text);
    }
}
