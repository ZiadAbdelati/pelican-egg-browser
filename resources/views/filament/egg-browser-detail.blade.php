<x-filament-panels::page>
    <div class="space-y-6">
        @if ($error)
            <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-600 dark:bg-danger-950 dark:text-danger-300">
                {{ $error }}
            </div>
        @endif

        @if ($catalogEgg)
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ $catalogEgg['name'] ?? $catalogEgg['slug'] ?? 'Egg' }}
                    </h2>
                    <div class="mt-1 flex flex-wrap gap-2 text-sm text-gray-500">
                        <span>{{ $catalogEgg['repository'] ?? '' }}</span>
                        <span>·</span>
                        <span>{{ $catalogEgg['category'] ?? '' }}</span>
                        @if (!empty($catalogEgg['author']))
                            <span>·</span>
                            <span>{{ $catalogEgg['author'] }}</span>
                        @endif
                    </div>
                </div>

                @php($status = $this->statusEnum())
                <div class="flex flex-col items-end gap-1">
                    <span @class([
                        'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium',
                        'bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300' => $status->color() === 'success',
                        'bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300' => $status->color() === 'warning',
                        'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300' => $status->color() === 'danger',
                        'bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300' => $status->color() === 'info',
                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $status->color() === 'gray',
                    ])>
                        {{ $status->displayName() }}
                    </span>
                    @if ($hasLocalChanges)
                        <span class="text-xs text-info-600 dark:text-info-300">
                            {{ __('egg-browser::strings.browser.local_changes_note') }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500">
                    {{ __('egg-browser::strings.browser.description') }}
                </h3>
                <p class="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-300">
                    {{ filled($catalogEgg['description'] ?? null) ? $catalogEgg['description'] : __('egg-browser::strings.browser.no_description') }}
                </p>
                <div class="mt-3 font-mono text-xs text-gray-400">
                    <div>{{ __('egg-browser::strings.browser.path') }}: {{ $catalogEgg['path'] ?? '' }}</div>
                    @if (!empty($catalogEgg['blob_sha']))
                        <div>{{ __('egg-browser::strings.browser.revision') }}: {{ $catalogEgg['blob_sha'] }}</div>
                    @endif
                    @if (!empty($catalogEgg['uuid']))
                        <div>UUID: {{ $catalogEgg['uuid'] }}</div>
                    @endif
                </div>
            </div>

            @php($summary = $this->summary())
            @if ($summary)
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            {{ __('egg-browser::strings.browser.manifest_summary') }}
                        </h3>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">{{ __('egg-browser::strings.browser.variables') }}</dt>
                                <dd>{{ $summary['variables_count'] ?? 0 }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Install container</dt>
                                <dd class="font-mono text-xs">{{ $summary['install_container'] ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Stop</dt>
                                <dd class="font-mono text-xs">{{ $summary['config_stop'] ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500">Export version</dt>
                                <dd class="font-mono text-xs">{{ $summary['meta_version'] ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            {{ __('egg-browser::strings.browser.docker_images') }}
                        </h3>
                        <ul class="space-y-1 font-mono text-xs text-gray-700 dark:text-gray-300">
                            @forelse (($summary['docker_images'] ?? []) as $label => $image)
                                <li><span class="text-gray-400">{{ $label }}:</span> {{ $image }}</li>
                            @empty
                                <li class="text-gray-400">—</li>
                            @endforelse
                        </ul>

                        <h3 class="mb-2 mt-4 text-sm font-semibold uppercase tracking-wide text-gray-500">
                            {{ __('egg-browser::strings.browser.startup') }}
                        </h3>
                        <ul class="space-y-1 font-mono text-xs text-gray-700 dark:text-gray-300">
                            @forelse (($summary['startup'] ?? []) as $label => $cmd)
                                <li class="break-all"><span class="text-gray-400">{{ $label }}:</span> {{ $cmd }}</li>
                            @empty
                                <li class="text-gray-400">—</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            @endif

            @if (!empty($diff))
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                            {{ __('egg-browser::strings.browser.diff') }}
                        </h3>
                        <div class="text-xs text-gray-500">
                            {{ __('egg-browser::strings.browser.diff_legend') }}
                        </div>
                    </div>

                    @if (!$hasUpstreamDiff)
                        <p class="text-sm text-success-700 dark:text-success-300">
                            {{ __('egg-browser::strings.browser.diff_identical') }}
                        </p>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-2 py-1">{{ __('egg-browser::strings.browser.diff_section') }}</th>
                                    <th class="px-2 py-1">Status</th>
                                    <th class="px-2 py-1">{{ __('egg-browser::strings.browser.diff_details') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($diff as $section => $info)
                                    <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                        <td class="px-2 py-2 font-mono text-xs">{{ $section }}</td>
                                        <td class="px-2 py-2">
                                            @if (!empty($info['changed']))
                                                <span class="rounded bg-warning-100 px-2 py-0.5 text-xs text-warning-800 dark:bg-warning-900 dark:text-warning-200">
                                                    {{ __('egg-browser::strings.browser.diff_changed') }}
                                                </span>
                                            @else
                                                <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                    {{ __('egg-browser::strings.browser.diff_unchanged') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-2">
                                            @if (!empty($info['changed']) && !empty($info['fields']))
                                                <div class="space-y-2">
                                                    @foreach ($info['fields'] as $field)
                                                        <div class="rounded border border-gray-100 p-2 dark:border-gray-800">
                                                            <div class="mb-1 font-mono text-[11px] text-gray-500">{{ $field['path'] }}</div>
                                                            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                                                                <div>
                                                                    <div class="mb-0.5 text-[11px] uppercase text-gray-400">{{ __('egg-browser::strings.browser.diff_local') }}</div>
                                                                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded bg-rose-50 p-2 text-[11px] text-rose-900 dark:bg-rose-950 dark:text-rose-100">{{ $this->formatDiffValue($field['left'] ?? null) }}</pre>
                                                                </div>
                                                                <div>
                                                                    <div class="mb-0.5 text-[11px] uppercase text-gray-400">{{ __('egg-browser::strings.browser.diff_upstream') }}</div>
                                                                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all rounded bg-emerald-50 p-2 text-[11px] text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">{{ $this->formatDiffValue($field['right'] ?? null) }}</pre>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @elseif (!empty($info['changed']))
                                                <span class="text-xs text-gray-500">{{ __('egg-browser::strings.browser.diff_complex') }}</span>
                                            @else
                                                <span class="text-xs text-gray-400">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if (!empty($unifiedDiffRows) || $localPrettyJson !== '' || $upstreamPrettyJson !== '' || $rawJson)
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-gray-500">
                        {{ __('egg-browser::strings.browser.raw_compare') }}
                    </h3>

                    @if (!empty($unifiedDiffRows))
                        <div class="mb-2 text-xs text-gray-500">
                            {{ __('egg-browser::strings.browser.unified_diff_help') }}
                        </div>
                        <div class="max-h-[32rem] overflow-auto rounded-lg bg-gray-950 p-3 font-mono text-xs leading-5">
                            @foreach ($unifiedDiffRows as $row)
                                @php
                                    $prefix = match ($row['tag'] ?? 'equal') {
                                        'add' => '+ ',
                                        'remove' => '- ',
                                        default => '  ',
                                    };
                                    $line = $prefix . ($row['text'] ?? '');
                                @endphp
                                <div @class([
                                    'block whitespace-pre-wrap break-all',
                                    $row['class'] ?? 'text-gray-300',
                                ])>{{ $line === '' ? ' ' : $line }}</div>
                            @endforeach
                        </div>
                    @elseif ($localPrettyJson !== '' && $upstreamPrettyJson !== '')
                        <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
                            <div>
                                <div class="mb-1 text-xs font-medium text-gray-500">{{ __('egg-browser::strings.browser.diff_local') }}</div>
                                <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $localPrettyJson }}</pre>
                            </div>
                            <div>
                                <div class="mb-1 text-xs font-medium text-gray-500">{{ __('egg-browser::strings.browser.diff_upstream') }}</div>
                                <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $upstreamPrettyJson }}</pre>
                            </div>
                        </div>
                    @elseif ($rawJson)
                        <div class="mb-1 text-xs font-medium text-gray-500">{{ __('egg-browser::strings.browser.raw_json') }}</div>
                        <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $rawJson }}</pre>
                    @elseif ($localPrettyJson !== '')
                        <div class="mb-1 text-xs font-medium text-gray-500">{{ __('egg-browser::strings.browser.diff_local') }}</div>
                        <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">{{ $localPrettyJson }}</pre>
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
