@php
    $amenities = $getAmenities();
    $categories = $getCategories($amenities);
    $statePath = $getStatePath();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{-- Like Airbnb: search, browse per category and a button per amenity
         on or off. The state is entangled with the field; for an
         existing accommodation every click saves immediately (see PropertyForm). --}}
    <div
        x-data="{
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            search: '',
            category: null,
            chosen(id) { return (this.state ?? []).map(String).includes(id) },
            swap(id) {
                const current = (this.state ?? []).map(String)
                this.state = current.includes(id) ? current.filter((x) => x !== id) : [...current, id]
            },
            visible(name, cat) {
                return (this.category === null || this.category === cat)
                    && name.toLowerCase().includes(this.search.trim().toLowerCase())
            },
        }"
        class="space-y-3"
    >
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative min-w-48 flex-1">
                <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                    <x-filament::input type="search" x-model="search" :placeholder="__('Search for an amenity')" />
                </x-filament::input.wrapper>
            </div>
            <span class="text-sm text-gray-500 dark:text-gray-400"
                  x-text="@js(__(':count selected')).replace(':count', (state ?? []).length)"></span>
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1">
            <button type="button" x-on:click="category = null"
                    x-bind:class="category === null ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-300 text-gray-700 hover:border-gray-900 dark:border-white/20 dark:text-gray-200'"
                    class="shrink-0 rounded-full border px-4 py-1.5 text-sm transition">
                {{ __('All') }}
            </button>
            @foreach ($categories as $key => $label)
                <button type="button" x-on:click="category = @js($key)"
                        x-bind:class="category === @js($key) ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-gray-300 text-gray-700 hover:border-gray-900 dark:border-white/20 dark:text-gray-200'"
                        class="shrink-0 rounded-full border px-4 py-1.5 text-sm transition">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="max-h-[28rem] overflow-y-auto rounded-xl border border-gray-200 dark:border-white/10">
            <div class="grid divide-y divide-gray-100 md:grid-cols-2 md:divide-y-0 dark:divide-white/5">
                @foreach ($amenities as $amenity)
                    <button type="button"
                            wire:key="amenity-{{ $amenity['id'] }}"
                            data-amenity="{{ $amenity['id'] }}"
                            x-show="visible(@js($amenity['name']), @js($amenity['category']))"
                            x-on:click="swap(@js($amenity['id']))"
                            x-bind:aria-pressed="chosen(@js($amenity['id'])) ? 'true' : 'false'"
                            class="flex items-center gap-3 border-gray-100 px-4 py-3 text-left transition hover:bg-gray-50 md:border-b dark:border-white/5 dark:hover:bg-white/5">
                        <x-site-icon :name="$amenity['icon']" class="h-6 w-6 shrink-0 text-gray-700 dark:text-gray-300" />
                        <span class="flex-1 text-sm text-gray-900 dark:text-white">{{ $amenity['name'] }}</span>
                        <span x-bind:class="chosen(@js($amenity['id'])) ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                              class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full transition">
                            {{-- x-show on a span: @js does not work inside a Blade component --}}
                            <span x-show="chosen(@js($amenity['id']))"><x-site-icon name="check" class="h-4 w-4" /></span>
                            <svg x-show="! chosen(@js($amenity['id']))" class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</x-dynamic-component>
