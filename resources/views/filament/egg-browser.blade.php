<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('egg-browser::strings.browser.subtitle') }}
        </p>

        @if ($indexError !== '')
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-600 dark:bg-danger-950 dark:text-danger-300">
                <div class="font-semibold">{{ __('egg-browser::strings.browser.index_error') }}</div>
                <div class="mt-1 font-mono text-xs break-all">{{ $indexError }}</div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="md:col-span-2">
                <label class="fi-fo-field-label-content mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    {{ __('egg-browser::strings.browser.search') }}
                </label>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    wire:keydown.enter.prevent
                    placeholder="{{ __('egg-browser::strings.browser.search_placeholder') }}"
                    class="fi-input block w-full rounded-lg border-none bg-white px-3 py-2 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 disabled:bg-gray-50 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500 dark:disabled:bg-transparent dark:disabled:text-gray-400 sm:text-sm sm:leading-6"
                />
            </div>

            <div>
                <label class="fi-fo-field-label-content mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    {{ __('egg-browser::strings.browser.repository') }}
                </label>
                <select
                    wire:model.live="filterRepository"
                    class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 disabled:bg-gray-50 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500 dark:disabled:bg-transparent sm:text-sm sm:leading-6"
                >
                    <option value="">{{ __('egg-browser::strings.browser.all_repositories') }}</option>
                    @foreach ($repositoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="fi-fo-field-label-content mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    {{ __('egg-browser::strings.browser.category') }}
                </label>
                <select
                    wire:model.live="filterCategory"
                    class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 disabled:bg-gray-50 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500 dark:disabled:bg-transparent sm:text-sm sm:leading-6"
                >
                    <option value="">{{ __('egg-browser::strings.browser.all_categories') }}</option>
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="fi-fo-field-label-content mb-1 block text-sm font-medium leading-6 text-gray-950 dark:text-white">
                    {{ __('egg-browser::strings.browser.status') }}
                </label>
                <select
                    wire:model.live="filterStatus"
                    class="fi-select-input block w-full rounded-lg border-none bg-white py-2 pe-8 ps-3 text-base text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 focus:ring-2 focus:ring-primary-600 disabled:bg-gray-50 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:bg-white/5 dark:text-white dark:ring-white/20 dark:focus:ring-primary-500 dark:disabled:bg-transparent sm:text-sm sm:leading-6"
                >
                    <option value="">{{ __('egg-browser::strings.browser.all_statuses') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500 dark:text-gray-400">
            <div>
                {{ trans_choice('egg-browser::strings.browser.results', $eggTotal, ['count' => $eggTotal]) }}
            </div>
            <div class="font-mono text-xs">
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
                        class="group flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                        wire:key="egg-{{ md5($egg['key']) }}"
                    >
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 group-hover:text-primary-600 dark:text-white">
                                {{ $egg['name'] }}
                            </h3>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300' => $egg['status_color'] === 'success',
                                'bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300' => $egg['status_color'] === 'warning',
                                'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300' => $egg['status_color'] === 'danger',
                                'bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300' => $egg['status_color'] === 'info',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $egg['status_color'] === 'gray',
                            ])>
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
</x-filament-panels::page>
