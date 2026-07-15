<x-filament-panels::page>
    <div class="space-y-6" x-data="{ activeTab: @js($activeTab) }">
        <div class="fi-sc-tabs">
            <div class="flex gap-1 rounded-xl bg-gray-50 p-1 text-sm font-medium ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" role="tablist" aria-label="Egg Browser sections">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-3 py-2 transition"
                    x-bind:class="activeTab === 'browser' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-900 dark:text-primary-400' : 'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white'"
                    x-on:click="activeTab = 'browser'; history.replaceState(null, '', new URL(Object.assign(new URL(window.location.href), { search: new URLSearchParams({ ...Object.fromEntries(new URLSearchParams(window.location.search)), activeTab: 'browser' }).toString() })).toString())"
                    role="tab"
                    x-bind:aria-selected="activeTab === 'browser'"
                >
                    <x-filament::icon icon="tabler-world-search" class="h-5 w-5" />
                    {{ __('egg-browser::strings.tabs.browser') }}
                </button>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-3 py-2 transition"
                    x-bind:class="activeTab === 'manage' ? 'bg-white text-primary-600 shadow-sm dark:bg-gray-900 dark:text-primary-400' : 'text-gray-600 hover:text-gray-950 dark:text-gray-400 dark:hover:text-white'"
                    x-on:click="activeTab = 'manage'; history.replaceState(null, '', new URL(Object.assign(new URL(window.location.href), { search: new URLSearchParams({ ...Object.fromEntries(new URLSearchParams(window.location.search)), activeTab: 'manage' }).toString() })).toString())"
                    role="tab"
                    x-bind:aria-selected="activeTab === 'manage'"
                >
                    <x-filament::icon icon="tabler-packages" class="h-5 w-5" />
                    {{ __('egg-browser::strings.tabs.manage') }}
                </button>
            </div>
        </div>

        <div x-show="activeTab === 'browser'" x-cloak>
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="space-y-6 p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.tabs.browser') }}
                            </h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ __('egg-browser::strings.browser.subtitle') }}
                            </p>
                        </div>

                        @if (\Community\EggBrowser\Filament\Admin\Pages\EggBrowserPage::canManage())
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::button
                                    color="gray"
                                    icon="tabler-refresh"
                                    wire:click="refreshIndex"
                                    wire:loading.attr="disabled"
                                    wire:target="refreshIndex"
                                >
                                    {{ __('egg-browser::strings.browser.refresh_index') }}
                                </x-filament::button>

                                <x-filament::button
                                    icon="tabler-cloud-search"
                                    wire:click="checkUpdates"
                                    wire:loading.attr="disabled"
                                    wire:target="checkUpdates"
                                >
                                    {{ __('egg-browser::strings.browser.check_updates') }}
                                </x-filament::button>
                            </div>
                        @endif
                    </div>

                    @if ($indexError !== '')
                        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-600 dark:bg-danger-950 dark:text-danger-300">
                            <div class="font-semibold">{{ __('egg-browser::strings.browser.index_error') }}</div>
                            <div class="mt-1 break-all font-mono text-xs">{{ $indexError }}</div>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <div class="md:col-span-2 xl:col-span-1">
                            <label class="mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.browser.search') }}
                            </label>
                            <input
                                type="search"
                                wire:model.live.debounce.250ms="search"
                                wire:keydown.enter.prevent
                                placeholder="{{ __('egg-browser::strings.browser.search_placeholder') }}"
                                class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 sm:text-sm sm:leading-6"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.browser.repository') }}
                            </label>
                            <select
                                wire:model.live="filterRepository"
                                class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm sm:leading-6"
                            >
                                <option value="">{{ __('egg-browser::strings.browser.all_repositories') }}</option>
                                @foreach ($repositoryOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.browser.category') }}
                            </label>
                            <select
                                wire:model.live="filterCategory"
                                class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm sm:leading-6"
                            >
                                <option value="">{{ __('egg-browser::strings.browser.all_categories') }}</option>
                                @foreach ($categoryOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.browser.tag') }}
                            </label>
                            <select
                                wire:model.live="filterTag"
                                class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm sm:leading-6"
                            >
                                <option value="">{{ __('egg-browser::strings.browser.all_tags') }}</option>
                                @foreach ($tagOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                {{ __('egg-browser::strings.browser.status') }}
                            </label>
                            <select
                                wire:model.live="filterStatus"
                                class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm sm:leading-6"
                            >
                                <option value="">{{ __('egg-browser::strings.browser.all_statuses') }}</option>
                                @foreach ($statusOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-500 dark:text-gray-400">
                            <div class="rounded-lg bg-gray-50 px-3 py-2 font-medium text-gray-700 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
                                {{ trans_choice('egg-browser::strings.browser.results', $eggTotal, ['count' => $eggTotal]) }}
                            </div>
                            <div class="rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                {{ __('egg-browser::strings.browser.rate_limit') }}: {{ $rateLimitText }}
                            </div>
                        </div>

                        @if (count($eggCards) === 0)
                            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700">
                                {{ __('egg-browser::strings.browser.empty') }}
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                                @foreach ($eggCards as $egg)
                                    <a
                                        href="{{ $this->detailUrl($egg['key']) }}"
                                        class="group flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-950"
                                        wire:key="egg-{{ md5($egg['key']) }}"
                                    >
                                        <div class="mb-2 flex items-start justify-between gap-3">
                                            <h3 class="min-w-0 flex-1 font-semibold text-gray-900 group-hover:text-primary-600 dark:text-white">
                                                {{ $egg['name'] }}
                                            </h3>
                                            <span class="shrink-0 whitespace-nowrap {{ $this->cardStatusBadgeClass($egg['status_color']) }}">
                                                {{ $egg['status_label'] }}
                                            </span>
                                        </div>

                                        <div class="mb-2 flex flex-wrap gap-1 text-xs">
                                            <span class="rounded bg-primary-50 px-1.5 py-0.5 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                                {{ $egg['repository_label'] }}
                                            </span>
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                                {{ $egg['category'] }}
                                            </span>
                                        </div>

                                        <p class="mb-3 line-clamp-3 flex-1 text-sm text-gray-600 dark:text-gray-400">
                                            {{ $egg['description'] }}
                                        </p>

                                        <div class="mt-auto space-y-1 font-mono text-[11px] text-gray-400">
                                            <div class="truncate" title="{{ $egg['path'] }}">{{ $egg['path'] }}</div>
                                            @if ($egg['blob_sha'] !== '')
                                                <div>{{ __('egg-browser::strings.browser.revision') }}: {{ substr($egg['blob_sha'], 0, 8) }}</div>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach
                            </div>

                            @if ($eggTotalPages > 1)
                                <div class="flex items-center justify-center gap-2 pt-2">
                                    <button
                                        type="button"
                                        wire:click="gotoPage({{ max(1, $catalogPage - 1) }})"
                                        @disabled($catalogPage <= 1)
                                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-40 dark:border-gray-700"
                                    >
                                        &larr;
                                    </button>
                                    <span class="text-sm text-gray-500">{{ $catalogPage }} / {{ $eggTotalPages }}</span>
                                    <button
                                        type="button"
                                        wire:click="gotoPage({{ min($eggTotalPages, $catalogPage + 1) }})"
                                        @disabled($catalogPage >= $eggTotalPages)
                                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-40 dark:border-gray-700"
                                    >
                                        &rarr;
                                    </button>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div x-show="activeTab === 'manage'" x-cloak>
            {{ $this->table }}
        </div>
    </div>
</x-filament-panels::page>
