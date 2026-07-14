<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        {{-- Tab bar --}}
        <div class="border-b border-gray-200 px-4 pt-3 dark:border-white/10 sm:px-6">
            <div class="flex flex-wrap gap-1">
                <button
                    type="button"
                    wire:click="setActiveTab('browser')"
                    class="inline-flex items-center gap-x-2 rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition {{ $activeTab === 'browser' ? 'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
                >
                    <x-filament::icon icon="tabler-world-search" class="h-4 w-4" />
                    {{ __('egg-browser::strings.tabs.browser') }}
                </button>

                <button
                    type="button"
                    wire:click="setActiveTab('manage')"
                    class="inline-flex items-center gap-x-2 rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition {{ $activeTab === 'manage' ? 'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
                >
                    <x-filament::icon icon="tabler-packages" class="h-4 w-4" />
                    {{ __('egg-browser::strings.tabs.manage') }}
                </button>
            </div>
        </div>

        <div class="p-4 sm:p-6">
            @if ($activeTab === 'browser')
                <div class="space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('egg-browser::strings.browser.subtitle') }}
                    </p>

                    @if ($indexError !== '')
                        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-600 dark:bg-danger-950 dark:text-danger-300">
                            <div class="font-semibold">{{ __('egg-browser::strings.browser.index_error') }}</div>
                            <div class="mt-1 break-all font-mono text-xs">{{ $indexError }}</div>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="md:col-span-2">
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
                                    class="group flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-950"
                                    wire:key="egg-{{ md5($egg['key']) }}"
                                >
                                    <div class="mb-2 flex items-start justify-between gap-2">
                                        <h3 class="font-semibold text-gray-900 group-hover:text-primary-600 dark:text-white">
                                            {{ $egg['name'] }}
                                        </h3>
                                        <span class="{{ $this->cardStatusBadgeClass($egg['status_color']) }}">
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
            @else
                <div class="space-y-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('egg-browser::strings.installed.subtitle') }}
                    </p>

                    @if ($this->tracked->isEmpty())
                        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700">
                            {{ __('egg-browser::strings.installed.empty') }}
                        </div>
                    @else
                        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-950">
                                    <tr>
                                        <th class="px-4 py-3">Egg</th>
                                        <th class="px-4 py-3">{{ __('egg-browser::strings.installed.source') }}</th>
                                        <th class="px-4 py-3">{{ __('egg-browser::strings.browser.status') }}</th>
                                        <th class="px-4 py-3">{{ __('egg-browser::strings.installed.last_checked') }}</th>
                                        <th class="px-4 py-3">{{ __('egg-browser::strings.browser.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                                    @foreach ($this->tracked as $row)
                                        @php($detailUrl = $this->trackedDetailUrl($row))
                                        <tr wire:key="tracked-{{ $row->id }}" class="hover:bg-gray-50/70 dark:hover:bg-gray-950/40">
                                            <td class="px-4 py-3">
                                                <a href="{{ $detailUrl }}" class="group block">
                                                    <div class="font-medium text-gray-900 group-hover:underline dark:text-white">
                                                        {{ $row->egg_name ?? ('#' . $row->egg_id) }}
                                                    </div>
                                                    <div class="font-mono text-xs text-gray-400">{{ $row->egg_uuid }}</div>
                                                </a>
                                            </td>
                                            <td class="px-4 py-3">
                                                <a href="{{ $detailUrl }}" class="block hover:opacity-80">
                                                    <div class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                                        {{ $row->source_owner }}/{{ $row->source_repo }}
                                                    </div>
                                                    <div class="truncate font-mono text-[11px] text-gray-400" title="{{ $row->source_path }}">
                                                        {{ $row->source_path }}
                                                    </div>
                                                </a>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="{{ $this->statusBadgeClass($row->status) }}">
                                                    {{ $this->statusLabel($row->status) }}
                                                </span>
                                                @if ($row->last_error)
                                                    <div class="mt-1 max-w-xs truncate text-xs text-danger-500" title="{{ $row->last_error }}">
                                                        {{ $row->last_error }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-500">
                                                {{ $row->last_checked_at?->diffForHumans() ?? '—' }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        wire:click="checkOne({{ $row->id }})"
                                                        class="rounded-lg border px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-950"
                                                    >
                                                        {{ __('egg-browser::strings.installed.check') }}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        wire:click="deleteEgg({{ $row->id }})"
                                                        wire:confirm="{{ __('egg-browser::strings.installed.delete_confirm') }}"
                                                        class="rounded-lg border border-danger-300 px-2 py-1 text-xs text-danger-700 hover:bg-danger-50 dark:border-danger-700 dark:text-danger-300 dark:hover:bg-danger-950"
                                                    >
                                                        {{ __('egg-browser::strings.installed.delete') }}
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
