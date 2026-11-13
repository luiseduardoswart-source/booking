{{-- Collapsed by default: with fifty amenities a list would make the tab very long.
     Open, a compact grid; the ones on the page have a coloured border. --}}
<div x-data="{ open: false }" class="space-y-2">
    @if ($amenities->isNotEmpty())
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" data-amenities-toggle
                class="flex items-center gap-2 text-sm font-medium text-gray-950 dark:text-white">
            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-400 transition" x-bind:class="open && 'rotate-90'" />
            {{ __('Order on the page') }}
            <span class="text-gray-500 dark:text-gray-400">({{ $amenities->count() }})</span>
        </button>

        <div x-show="open" x-cloak class="space-y-2">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Drag to reorder. The first :count, with a coloured border, appear on the page; the rest under "Show all amenities".', ['count' => $onThePage]) }}
            </p>

            {{-- forceFallback: drag with the mouse instead of browser dragging, like the photos. --}}
            <ol wire:sort="move" wire:sort:config="{ forceFallback: true }" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($amenities as $amenity)
                    @php($onPage = $loop->index < $onThePage)
                    <li
                        wire:key="amenity-{{ $amenity->id }}"
                        wire:sort:item="{{ $amenity->id }}"
                        @if ($onPage) data-on-page @endif
                        @class([
                            'flex min-w-0 cursor-move items-center gap-2 rounded-lg border bg-white px-2 py-1.5 text-sm dark:bg-gray-900',
                            'border-primary-500 dark:border-primary-400' => $onPage,
                            'border-gray-200 dark:border-gray-700' => ! $onPage,
                        ])
                        title="{{ $amenity->localizedName() }}"
                    >
                        <span @class(['w-5 shrink-0 text-right text-xs tabular-nums', 'font-semibold text-primary-600 dark:text-primary-400' => $onPage, 'text-gray-400' => ! $onPage])>{{ $loop->iteration }}</span>
                        <x-site-icon :name="$amenity->iconName()" class="h-4 w-4 shrink-0 text-gray-700 dark:text-gray-300" />
                        <span class="truncate text-gray-950 dark:text-white">{{ $amenity->localizedName() }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
