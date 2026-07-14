<x-filament-panels::page>
    @php
        $eggs = $this->eggs;
        $paginatedEggs = $this->paginatedEggs;
        $totalPages = $this->totalPages;
        $currentPage = (int) $this->catalogPage;
        $repositoryOptions = $this->repositoryOptions;
        $categoryOptions = $this->categoryOptions;
        $statusOptions = $this->statusOptions;
        $rateLimit = $this->rateLimit;
        $eggCount = $eggs->count();

        $statusMeta = [
            'not_installed' => ['label' => 'Not installed', 'color' => 'gray'],
            'up_to_date' => ['label' => 'Up to date', 'color' => 'success'],
            'update_available' => ['label' => 'Update available', 'color' => 'warning'],
            'local_changes' => ['label' => 'Local changes detected', 'color' => 'info'],
            'local_changes_and_update' => ['label' => 'Local changes + update', 'color' => 'info'],
            'source_unavailable' => ['label' => 'Source unavailable', 'color' => 'danger'],
            'unknown_unlinked' => ['label' => 'Unknown/unlinked', 'color' => 'gray'],
            'check_failed' => ['label' => 'Check failed', 'color' => 'danger'],
        ];
    @endphp

    <div class="space-y-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('egg-browser::strings.browser.subtitle') }}
        </p>

        @if (filled($this->indexError))
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-600 dark:bg-danger-950 dark:text-danger-300">
                <div class="font-semibold">{{ __('egg-browser::strings.browser.index_error') }}</div>
                <div class="mt-1 font-mono text-xs">{{ $this->indexError }}</div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="md:col-span-2">
                <label class="mb-1 block text-sm font-medium">{{ __('egg-browser::strings.browser.search') }}</label>
                <input
                    type="search"
                    wire:model.live.debounce.400ms="q"
                    placeholder="{{ __('egg-browser::strings.browser.search_placeholder') }}"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                />
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('egg-browser::strings.browser.repository') }}</label>
                <select wire:model.live="repository" class="w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <option value="">{{ __('egg-browser::strings.browser.all_repositories') }}</option>
                    @foreach ($repositoryOptions as $value => $label)
                        <option value="{{ is_scalar($value) ? $value : '' }}">{{ is_scalar($label) ? $label : '' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('egg-browser::strings.browser.category') }}</label>
                <select wire:model.live="category" class="w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <option value="">{{ __('egg-browser::strings.browser.all_categories') }}</option>
                    @foreach ($categoryOptions as $value => $label)
                        <option value="{{ is_scalar($value) ? $value : '' }}">{{ is_scalar($label) ? $label : '' }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('egg-browser::strings.browser.status') }}</label>
                <select wire:model.live="statusFilter" class="w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                    <option value="">{{ __('egg-browser::strings.browser.all_statuses') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ is_scalar($value) ? $value : '' }}">{{ is_scalar($label) ? $label : '' }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-500 dark:text-gray-400">
            <div>
                {{ trans_choice('egg-browser::strings.browser.results', $eggCount, ['count' => $eggCount]) }}
            </div>
            <div class="font-mono text-xs">
                {{ __('egg-browser::strings.browser.rate_limit') }}:
                @if (is_array($rateLimit) && array_key_exists('remaining', $rateLimit) && !is_null($rateLimit['remaining']))
                    {{ $rateLimit['remaining'] }}/{{ $rateLimit['rate_limit'] ?? '?' }}
                    @if (!empty($rateLimit['authenticated']))
                        (auth)
                    @else
                        (anon)
                    @endif
                @else
                    n/a
                @endif
            </div>
        </div>

        @if ($paginatedEggs->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700">
                {{ __('egg-browser::strings.browser.empty') }}
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($paginatedEggs as $egg)
                    @php
                        $statusValue = is_array($egg) ? (string) ($egg['install_status'] ?? 'not_installed') : 'not_installed';
                        $meta = $statusMeta[$statusValue] ?? ['label' => $statusValue, 'color' => 'gray'];
                        $color = $meta['color'];
                        $eggKey = is_array($egg) ? (string) ($egg['key'] ?? '') : '';
                        $eggName = is_array($egg) ? (string) ($egg['name'] ?? $egg['slug'] ?? 'Egg') : 'Egg';
                        $eggRepoLabel = is_array($egg) ? (string) ($egg['repository_label'] ?? $egg['repository'] ?? '') : '';
                        $eggCategory = is_array($egg) ? (string) ($egg['category'] ?? '—') : '—';
                        $eggDescription = is_array($egg) && is_string($egg['description'] ?? null) && $egg['description'] !== ''
                            ? $egg['description']
                            : __('egg-browser::strings.browser.no_description');
                        $eggPath = is_array($egg) ? (string) ($egg['path'] ?? '') : '';
                        $eggBlob = is_array($egg) && is_string($egg['blob_sha'] ?? null) ? $egg['blob_sha'] : null;
                    @endphp

                    <a
                        href="{{ $this->detailUrl($eggKey) }}"
                        class="group flex flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
                        wire:key="egg-{{ md5($eggKey) }}"
                    >
                        <div class="mb-2 flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 group-hover:text-primary-600 dark:text-white">
                                {{ $eggName }}
                            </h3>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300' => $color === 'success',
                                'bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300' => $color === 'warning',
                                'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300' => $color === 'danger',
                                'bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300' => $color === 'info',
                                'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $color === 'gray',
                            ])>
                                {{ $meta['label'] }}
                            </span>
                        </div>

                        <div class="mb-2 flex flex-wrap gap-1 text-xs">
                            <span class="rounded bg-primary-50 px-1.5 py-0.5 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                                {{ $eggRepoLabel }}
                            </span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                {{ $eggCategory }}
                            </span>
                        </div>

                        <p class="mb-3 line-clamp-3 flex-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $eggDescription }}
                        </p>

                        <div class="mt-auto space-y-1 font-mono text-[11px] text-gray-400">
                            <div class="truncate" title="{{ $eggPath }}">{{ $eggPath }}</div>
                            @if ($eggBlob)
                                <div>{{ __('egg-browser::strings.browser.revision') }}: {{ substr($eggBlob, 0, 8) }}</div>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($totalPages > 1)
                <div class="flex items-center justify-center gap-2 pt-2">
                    <button
                        type="button"
                        wire:click="gotoPage({{ max(1, $currentPage - 1) }})"
                        @disabled($currentPage <= 1)
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-40 dark:border-gray-700"
                    >
                        &larr;
                    </button>
                    <span class="text-sm text-gray-500">{{ $currentPage }} / {{ $totalPages }}</span>
                    <button
                        type="button"
                        wire:click="gotoPage({{ min($totalPages, $currentPage + 1) }})"
                        @disabled($currentPage >= $totalPages)
                        class="rounded-lg border px-3 py-1 text-sm disabled:opacity-40 dark:border-gray-700"
                    >
                        &rarr;
                    </button>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
