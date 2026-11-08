<x-site-layout :metaDescription="\App\Models\Setting::localized('company', 'slogan')">
    <section class="border-b border-stone-100 bg-gradient-to-b from-rose-50 to-white">
        <div class="mx-auto max-w-7xl px-4 py-16 text-center sm:px-6">
            <h1 class="text-4xl font-bold tracking-tight text-stone-900 sm:text-5xl">
                {{ \App\Models\Setting::localized('company', 'slogan', __('Your next stay starts here')) }}
            </h1>
            <p class="mx-auto mt-4 max-w-2xl text-lg text-stone-600">
                {{ __('Book directly, without intermediaries — always the best price and personal contact.') }}
            </p>

            {{-- Search bar with the same calendar and guest picker as the detail page --}}
            <form method="get" action="{{ route('home') }}" class="relative mx-auto mt-10 max-w-4xl">
                <input type="hidden" name="arrival" value="{{ $filters['arrival'] }}">
                <input type="hidden" name="departure" value="{{ $filters['departure'] }}">

                <div class="flex flex-col gap-2 rounded-2xl border border-stone-200 bg-white p-2 text-left shadow-xl sm:flex-row sm:items-center sm:rounded-full">
                    @include('site.partials.where-field')
                    <button type="button" data-calendar-field="arrival" class="flex-1 border-stone-200 px-5 py-2 text-left transition hover:bg-stone-50 sm:border-r">
                        <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Check-in') }}</span>
                        <span data-calendar-text="arrival" class="text-sm text-stone-500">{{ __('Select date') }}</span>
                    </button>
                    <button type="button" data-calendar-field="departure" class="flex-1 border-stone-200 px-5 py-2 text-left transition hover:bg-stone-50 sm:border-r">
                        <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Check-out') }}</span>
                        <span data-calendar-text="departure" class="text-sm text-stone-500">{{ __('Select date') }}</span>
                    </button>
                    <button type="button" data-guests-field class="flex-1 px-5 py-2 text-left transition hover:bg-stone-50">
                        <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Guests') }}</span>
                        <span data-guests-text class="text-sm text-stone-500"></span>
                    </button>
                    <button type="submit"
                            class="flex items-center justify-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3.5 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700 sm:rounded-full">
                        <x-site-icon name="magnifying-glass" class="h-5 w-5" />
                        {{ __('Search') }}
                    </button>
                </div>

                <x-guest-popover
                    :max="$maxGuests"
                    :petsAllowed="true"
                    class="right-0 top-full w-80" />

                <x-calendar-popover class="left-1/2 top-full -translate-x-1/2" />

                {{-- Filters: own row below the search bar --}}
                @if ($multipleListings)
                    <div class="mt-4 flex justify-center">
                        @include('site.partials.filter-bar')
                    </div>
                @endif
            </form>
        </div>
    </section>

    @php $withMap = $multipleListings && $mapData->isNotEmpty(); @endphp

    <section class="mx-auto max-w-7xl px-4 py-14 sm:px-6" id="accommodations">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                @if ($filters['arrival'] || $filters['guests'] || array_filter([$filters['where'], $filters['type'], $filters['price_max'], $filters['amenity'], $filters['bedrooms'], $filters['bathrooms']]) !== [])
                    <p class="text-sm text-stone-600">
                        {{ trans_choice('{0} No accommodations found for this search.|{1} 1 accommodation available.|[2,*] :count accommodations available.', $properties->count()) }}
                        <a href="{{ route('home') }}" class="ml-2 underline hover:text-rose-600">{{ __('Clear filters') }}</a>
                    </p>
                @else
                    <h2 class="text-2xl font-bold text-stone-900">{{ __('Our accommodations') }}</h2>
                @endif
            </div>

            @if ($withMap)
                @include('site.partials.view-tabs')
            @endif
        </div>

        <div data-panel="list">
            @if ($properties->isEmpty())
                <p class="mt-6 text-stone-600">{{ __('No accommodations are available at the moment.') }}</p>
            @else
                <div class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($properties as $property)
                        <x-property-card
                            :property="$property"
                            :totalCents="$totals[$property->id] ?? null"
                            :nights="$nights"
                            :query="array_filter($filters)" />
                    @endforeach
                </div>
            @endif
        </div>

        @if ($withMap)
            <div data-panel="map" class="hidden">
                @include('site.partials.map-explorer')
            </div>
        @endif
    </section>
</x-site-layout>
