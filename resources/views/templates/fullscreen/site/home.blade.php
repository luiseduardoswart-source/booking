{{-- Layout "Fullscreen magazine": full-page hero + accommodations as large rows. --}}
<x-site-layout :title="\App\Support\Seo::homeTitle()" :metaDescription="\App\Support\Seo::homeDescription()">
    @php $heroPhoto = $properties->first(fn ($p) => $p->coverPhotoUrl('large') !== '')?->coverPhotoUrl('large'); @endphp

    <section class="relative flex min-h-[calc(100vh-4.5rem)] items-center justify-center overflow-hidden" data-layout="fullscreen">
        @if ($heroPhoto)
            <img src="{{ $heroPhoto }}" alt="" class="absolute inset-0 h-full w-full object-cover">
            <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-black/35 to-black/60"></div>
        @else
            <div class="absolute inset-0 bg-gradient-to-b from-stone-900 to-stone-700"></div>
        @endif

        <div class="relative mx-auto w-full max-w-4xl px-4 py-20 text-center sm:px-6">
            <h1 class="text-4xl font-bold tracking-tight text-white drop-shadow sm:text-6xl">
                {{ \App\Models\Setting::localized('company', 'slogan', __('Your next stay starts here')) }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-lg text-white/85">
                {{ __('Book directly, without intermediaries — always the best price and personal contact.') }}
            </p>

            {{-- The same search bar (calendar + guest picker) as the default layout --}}
            <form method="get" action="{{ \App\Support\LocalizedUrl::home() }}" class="relative mx-auto mt-10 max-w-4xl">
                <input type="hidden" name="arrival" value="{{ $filters['arrival'] }}">
                <input type="hidden" name="departure" value="{{ $filters['departure'] }}">

                <div class="flex flex-col gap-2 rounded-2xl border border-white/20 bg-white/95 p-2 text-left shadow-2xl backdrop-blur sm:flex-row sm:items-center sm:rounded-full">
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

            <a href="#accommodations" class="mt-12 inline-flex flex-col items-center gap-1 text-sm font-medium text-white/80 transition hover:text-white">
                {{ __('Our accommodations') }}
                <svg class="h-5 w-5 animate-bounce" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </a>
        </div>
    </section>

    @php $withMap = $multipleListings && $mapData->isNotEmpty(); @endphp

    <section class="mx-auto max-w-6xl px-4 py-16 sm:px-6" id="accommodations">
        <div class="flex flex-wrap items-center justify-between gap-4 {{ ($filters['arrival'] || $filters['guests'] || $withMap) ? 'mb-8' : '' }}">
            <div>
                @if ($filters['arrival'] || $filters['guests'])
                    <p class="text-sm text-stone-600">
                        {{ trans_choice('{0} No accommodations found for this search.|{1} 1 accommodation available.|[2,*] :count accommodations available.', $properties->count()) }}
                        <a href="{{ \App\Support\LocalizedUrl::home() }}" class="ml-2 underline hover:text-rose-600">{{ __('Clear filters') }}</a>
                    </p>
                @endif
            </div>

            @if ($withMap)
                @include('site.partials.view-tabs')
            @endif
        </div>

        @if ($properties->isEmpty() && ! $filters['arrival'] && ! $filters['guests'])
            <p class="text-stone-600">{{ __('No accommodations are available at the moment.') }}</p>
        @endif

        @if ($withMap)
            <div data-panel="map" class="hidden">
                @include('site.partials.map-explorer')
            </div>
        @endif

        <div data-panel="list" class="space-y-16">
            @foreach ($properties as $property)
                @php $link = \App\Support\LocalizedUrl::of($property, null, array_filter($filters)); @endphp
                <article class="group flex flex-col gap-6 md:items-center {{ $loop->even ? 'md:flex-row-reverse' : 'md:flex-row' }}">
                    <a href="{{ $link }}" class="block overflow-hidden rounded-3xl md:w-3/5">
                        @if ($photo = $property->coverPhotoUrl('large'))
                            <img src="{{ $photo }}" alt="{{ $property->localized('name') }}"
                                 class="aspect-[4/3] w-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        @else
                            <div class="aspect-[4/3] w-full bg-stone-200"></div>
                        @endif
                    </a>

                    <div class="md:w-2/5 {{ $loop->even ? 'md:pr-4' : 'md:pl-4' }}">
                        <p class="text-sm uppercase tracking-wide text-stone-500">
                            {{ $property->typeName() }}{{ $property->city ? ' · '.$property->city : '' }}
                        </p>
                        <h2 class="mt-1 text-2xl font-bold text-stone-900 sm:text-3xl">
                            <a href="{{ $link }}" class="hover:text-rose-600">{{ $property->localized('name') }}</a>
                        </h2>

                        @if ($property->approved_reviews_count > 0)
                            <p class="mt-2 text-sm text-stone-600">
                                <span class="text-amber-500">★</span>
                                {{ number_format((float) $property->approved_reviews_avg_rating, 1, ',', '') }}
                                · {{ trans_choice('{1} 1 review|[2,*] :count reviews', $property->approved_reviews_count) }}
                            </p>
                        @endif

                        @if ($property->localized('short_description'))
                            <p class="mt-3 text-stone-600">{{ $property->localized('short_description') }}</p>
                        @endif

                        <p class="mt-4 font-semibold text-stone-900">
                            @if (isset($totals[$property->id]) && $nights)
                                {{ \App\Support\Money::format($totals[$property->id], $property->priceCurrency()) }}
                                <span class="font-normal text-stone-500">{{ trans_choice('{1} total for 1 night|[2,*] total for :count nights', $nights) }}</span>
                            @elseif ($property->basePrice() > 0)
                                {{ \App\Support\Money::format($property->basePrice(), $property->priceCurrency()) }}
                                <span class="font-normal text-stone-500">{{ __('per night') }}</span>
                            @endif
                        </p>

                        <a href="{{ $link }}"
                           class="mt-5 inline-flex items-center gap-2 rounded-full button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                            {{ __('View & book') }}
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
</x-site-layout>
