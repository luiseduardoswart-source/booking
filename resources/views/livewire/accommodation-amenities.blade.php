<div class="space-y-2">
    @if ($amenities->isNotEmpty())
        <div>
            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __('Order on the page') }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Drag to reorder. The first :count appear on the page, the rest under "Show all amenities".', ['count' => $onThePage]) }}
            </p>
        </div>

        {{-- forceFallback: drag with the mouse instead of browser dragging, like the photos. --}}
        <ol wire:sort="move" wire:sort:config="{ forceFallback: true }" class="space-y-1">
            @foreach ($amenities as $amenity)
                <li
                    wire:key="amenity-{{ $amenity->id }}"
                    wire:sort:item="{{ $amenity->id }}"
                    @class([
                        'flex cursor-move items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900',
                        'mt-4' => $loop->index === $onThePage,
                    ])
                    @if ($loop->index === $onThePage) data-amenities-rest @endif
                >
                    <x-filament::icon icon="heroicon-o-bars-3" class="h-4 w-4 shrink-0 text-gray-400" />
                    <span class="w-6 shrink-0 text-right tabular-nums text-gray-400">{{ $loop->iteration }}</span>
                    <x-site-icon :name="$amenity->iconName()" class="h-5 w-5 shrink-0 text-gray-700 dark:text-gray-300" />
                    <span class="text-gray-950 dark:text-white">{{ $amenity->localizedName() }}</span>
                </li>
            @endforeach
        </ol>
    @endif
</div>
