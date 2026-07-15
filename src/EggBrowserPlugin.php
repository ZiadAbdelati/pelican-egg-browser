<?php

namespace Community\EggBrowser;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Community\EggBrowser\Services\RepositoryConfigService;
use Community\EggBrowser\Services\TrackedEggSyncService;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\Artisan;

class EggBrowserPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'egg-browser';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(
            plugin_path($this->getId(), "src/Filament/{$id}/Pages"),
            "Community\\EggBrowser\\Filament\\{$id}\\Pages"
        );

        $panel->discoverResources(
            plugin_path($this->getId(), "src/Filament/{$id}/Resources"),
            "Community\\EggBrowser\\Filament\\{$id}\\Resources"
        );
    }

    public function boot(Panel $panel): void {}

    public function getSettingsForm(): array
    {
        $repos = app(RepositoryConfigService::class)->configuredRepositories();

        $repoLines = collect($repos)
            ->map(function (array $repo) {
                $line = "{$repo['owner']}/{$repo['name']}";
                if (!empty($repo['branch']) && $repo['branch'] !== 'main') {
                    $line .= '@' . $repo['branch'];
                }
                if (empty($repo['enabled'])) {
                    $line .= ' #disabled';
                }

                return $line;
            })
            ->implode("\n");

        return [
            TextInput::make('github_token')
                ->label(trans('egg-browser::strings.settings.github_token'))
                ->password()
                ->revealable()
                ->helperText(trans('egg-browser::strings.settings.github_token_help'))
                ->default(fn () => config('egg-browser.github_token'))
                ->columnSpanFull(),

            Textarea::make('repositories')
                ->label(trans('egg-browser::strings.settings.repositories'))
                ->helperText(trans('egg-browser::strings.settings.repositories_help'))
                ->rows(12)
                ->default($repoLines)
                ->columnSpanFull(),

            Select::make('tag_strategy')
                ->label(trans('egg-browser::strings.settings.tag_strategy'))
                ->options([
                    'repository' => trans('egg-browser::strings.settings.tag_strategy_repository'),
                    'category' => trans('egg-browser::strings.settings.tag_strategy_category'),
                    'custom' => trans('egg-browser::strings.settings.tag_strategy_custom'),
                    'ask' => trans('egg-browser::strings.settings.tag_strategy_ask'),
                ])
                ->default(fn () => config('egg-browser.install.tag_strategy', 'repository'))
                ->required(),

            TagsInput::make('default_tags')
                ->label(trans('egg-browser::strings.settings.default_tags'))
                ->helperText(trans('egg-browser::strings.settings.default_tags_help'))
                ->default(fn () => config('egg-browser.install.default_tags', [])),

            Toggle::make('prefer_pelican_json')
                ->label(trans('egg-browser::strings.settings.prefer_pelican_json'))
                ->helperText(trans('egg-browser::strings.settings.prefer_pelican_json_help'))
                ->inline(false)
                ->default(fn () => (bool) config('egg-browser.install.prefer_pelican_json', true)),

            Toggle::make('set_update_url')
                ->label(trans('egg-browser::strings.settings.set_update_url'))
                ->helperText(trans('egg-browser::strings.settings.set_update_url_help'))
                ->inline(false)
                ->default(fn () => (bool) config('egg-browser.install.set_update_url', true)),

            Toggle::make('schedule_enabled')
                ->label(trans('egg-browser::strings.settings.schedule_enabled'))
                ->inline(false)
                ->default(fn () => (bool) config('egg-browser.schedule.enabled', true)),

            TextInput::make('schedule_cron')
                ->label(trans('egg-browser::strings.settings.schedule_cron'))
                ->helperText(trans('egg-browser::strings.settings.schedule_cron_help'))
                ->default(fn () => config('egg-browser.schedule.cron', '0 3 * * *')),

            TextInput::make('index_ttl')
                ->label(trans('egg-browser::strings.settings.index_ttl'))
                ->numeric()
                ->minValue(60)
                ->default(fn () => (int) config('egg-browser.cache.index_ttl', 3600)),

            TextInput::make('manifest_ttl')
                ->label(trans('egg-browser::strings.settings.manifest_ttl'))
                ->numeric()
                ->minValue(60)
                ->default(fn () => (int) config('egg-browser.cache.manifest_ttl', 1800)),

            Section::make(trans('egg-browser::strings.settings.sync_installed_eggs'))
                ->description(trans('egg-browser::strings.settings.sync_installed_eggs_help'))
                ->headerActions([
                    Action::make('syncInstalledEggs')
                        ->label(trans('egg-browser::strings.settings.sync_installed_eggs_button'))
                        ->icon('tabler-link')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalDescription(trans('egg-browser::strings.settings.sync_installed_eggs_help'))
                        ->action(function (): void {
                            $sync = app(TrackedEggSyncService::class);
                            $sync->pruneOrphans();
                            $result = $sync->autoLinkFromUpdateUrls(checkUpstream: true);

                            $body = trans('egg-browser::strings.notifications.link_stats', [
                                'local_total' => $result['stats']['local_total'] ?? 0,
                                'local_untracked' => $result['stats']['local_untracked'] ?? 0,
                                'catalog_total' => $result['stats']['catalog_total'] ?? 0,
                                'matched' => $result['stats']['matched'] ?? 0,
                            ]);

                            if (!empty($result['errors'])) {
                                $body .= "\n" . implode("\n", array_slice($result['errors'], 0, 5));
                            }

                            Notification::make()
                                ->title(trans('egg-browser::strings.notifications.link_done', [
                                    'linked' => $result['linked'],
                                    'checked' => $result['checked'],
                                    'skipped' => $result['skipped'],
                                ]))
                                ->body($body)
                                ->success()
                                ->send();
                        }),
                ])
                ->columnSpanFull(),
        ];
    }

    public function saveSettings(array $data): void
    {
        $defaultTags = $data['default_tags'] ?? [];
        if (is_string($defaultTags)) {
            $defaultTags = array_values(array_filter(array_map('trim', explode(',', $defaultTags))));
        }

        $this->writeToEnvironment([
            'EGG_BROWSER_GITHUB_TOKEN' => $data['github_token'] ?? '',
            'EGG_BROWSER_TAG_STRATEGY' => $data['tag_strategy'] ?? 'repository',
            'EGG_BROWSER_DEFAULT_TAGS' => implode(',', $defaultTags),
            'EGG_BROWSER_PREFER_PELICAN_JSON' => !empty($data['prefer_pelican_json']) ? 'true' : 'false',
            'EGG_BROWSER_SET_UPDATE_URL' => !empty($data['set_update_url']) ? 'true' : 'false',
            'EGG_BROWSER_SCHEDULE_ENABLED' => !empty($data['schedule_enabled']) ? 'true' : 'false',
            'EGG_BROWSER_SCHEDULE_CRON' => $data['schedule_cron'] ?? '0 3 * * *',
            'EGG_BROWSER_INDEX_TTL' => (string) ($data['index_ttl'] ?? 3600),
            'EGG_BROWSER_MANIFEST_TTL' => (string) ($data['manifest_ttl'] ?? 1800),
            'EGG_BROWSER_EXTRA_REPOSITORIES' => $this->repositoriesToEnvCsv((string) ($data['repositories'] ?? '')),
        ]);

        // Persist the full repository list (including official ones) as structured config override.
        app(RepositoryConfigService::class)->persistRepositoryList((string) ($data['repositories'] ?? ''));

        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // Config clear may be unavailable in some installs; env write still applies on next boot.
        }

        Notification::make()
            ->title(trans('egg-browser::strings.settings.saved'))
            ->success()
            ->send();
    }

    /**
     * Convert the free-form repository textarea into the EXTRA_REPOSITORIES CSV,
     * keeping only entries that are not part of the built-in official defaults
     * OR that change branch/enabled state (handled by RepositoryConfigService).
     */
    protected function repositoriesToEnvCsv(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip trailing comments
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }

            $entries[] = $line;
        }

        return implode(',', $entries);
    }
}
