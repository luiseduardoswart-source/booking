<div class="space-y-4">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('The fixed words of the website, such as Search and Book, per language. Changes here are saved immediately and only apply to the website, not to the admin. Empty = the default text.') }}
    </p>

    <div class="flex items-center gap-3">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            x-on:keydown.enter.prevent
            placeholder="{{ __('Search for a text…') }}"
            class="w-full min-w-0 flex-1 rounded-lg border-gray-300 py-2.5 pl-3 pr-3 text-sm leading-6 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
        />
        <select
            wire:model.live="kind"
            class="rounded-lg border-gray-300 py-2.5 pl-3 pr-9 text-sm leading-6 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
        >
            <option value="">{{ __('All') }}</option>
            @foreach ($kinds as $kind)
                <option value="{{ $kind }}">{{ __($kind) }}</option>
            @endforeach
        </select>
    </div>

    @if ($paginator->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No texts found.') }}</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full table-fixed">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        @foreach ($languages as $locale)
                            <th class="w-56 px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ \App\Support\Languages::name($locale) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paginator as $row)
                        <tr wire:key="text-{{ $row['hash'] }}" class="border-b border-gray-200 align-top last:border-0 dark:border-gray-700">
                            @foreach ($languages as $locale)
                                <td class="p-1.5" wire:key="text-{{ $row['hash'] }}-{{ $locale }}" x-data="{ saved: false, error: null }">
                                    <span class="relative block">
                                        <input
                                            type="text"
                                            value="{{ $row['values'][$locale] }}"
                                            aria-label="{{ \App\Support\Languages::name($locale) }}"
                                            title="{{ $row['values'][$locale] }}"
                                            x-on:change="$wire.save(@js($row['hash']), @js($locale), $event.target.value).then((notification) => { error = notification; saved = ! notification; setTimeout(() => saved = false, 2000) })"
                                            x-on:keydown.enter.prevent="$el.blur()"
                                            class="w-full min-w-0 rounded-lg border-gray-300 py-2 pl-2.5 pr-8 text-sm leading-6 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                        <span x-show="saved" x-cloak class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-success-600 dark:text-success-400">
                                            <x-filament::icon icon="heroicon-o-check" class="h-5 w-5" />
                                        </span>
                                    </span>
                                    <span x-show="error" x-cloak x-text="error" class="mt-1 block text-sm text-danger-600 dark:text-danger-400"></span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <x-filament::pagination :paginator="$paginator" />
</div>
