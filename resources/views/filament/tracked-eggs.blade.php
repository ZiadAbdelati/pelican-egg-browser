<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ trans('egg-browser::strings.installed.subtitle') }}
        </p>

        @if ($this->tracked->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700">
                {{ trans('egg-browser::strings.installed.empty') }}
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-3">Egg</th>
                            <th class="px-4 py-3">{{ trans('egg-browser::strings.installed.source') }}</th>
                            <th class="px-4 py-3">{{ trans('egg-browser::strings.browser.status') }}</th>
                            <th class="px-4 py-3">{{ trans('egg-browser::strings.installed.last_checked') }}</th>
                            <th class="px-4 py-3">{{ trans('egg-browser::strings.browser.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-950">
                        @foreach ($this->tracked as $row)
                            @php($detailUrl = $this->detailUrl($row))
                            <tr wire:key="tracked-{{ $row->id }}" class="hover:bg-gray-50/70 dark:hover:bg-gray-900/40">
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
                                    @if (\Community\EggBrowser\Filament\Admin\Pages\EggBrowserPage::canManage())
                                        <div class="flex flex-wrap gap-3">
                                            <button
                                                type="button"
                                                wire:click="checkOne({{ $row->id }})"
                                                class="rounded-lg border px-2 py-1 text-xs text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-900"
                                            >
                                                {{ trans('egg-browser::strings.installed.check') }}
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
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
