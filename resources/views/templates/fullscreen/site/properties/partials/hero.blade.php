{{-- Layout "Fullscreen magazine": full-width photo hero with the title on top. --}}
<section data-hero="fullscreen">
    @if ($photos->isNotEmpty())
        <div class="relative h-[55vh] min-h-[380px] w-full overflow-hidden">
            <x-property-media :media="$photos->first()" conversion="large" :alt="$property->localized('name')" class="h-full w-full object-cover" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-black/30"></div>

            <div class="absolute inset-x-0 bottom-0">
                <div class="mx-auto flex max-w-7xl flex-wrap items-end justify-between gap-4 px-4 pb-8 sm:px-6">
                    <div>
                        <h1 class="text-3xl font-bold text-white drop-shadow sm:text-5xl">{{ $property->localized('name') }}</h1>
                        <p class="mt-2 flex flex-wrap items-center gap-x-2 text-sm text-white/85">
                            @if ($property->approved_reviews_count > 0)
                                <span class="flex items-center gap-1 font-medium text-white">
                                    <span class="text-base">★</span>
                                    {{ number_format($property->approved_reviews_avg_rating, 1, ',', '') }}
                                </span>
                                <a href="#reviews" class="underline">{{ trans_choice('{1} 1 review|[2,*] :count reviews', $property->approved_reviews_count) }}</a>
                                <span>·</span>
                            @endif
                            @if ($property->city)<span class="font-medium">{{ $property->city }}, {{ \App\Support\Countries::name($property->country) }}</span>@endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button onclick="share_dialog.showModal()"
                                class="flex items-center gap-2 rounded-full bg-white/90 px-4 py-2 text-sm font-medium text-stone-900 shadow transition hover:bg-white">
                            <x-site-icon name="share" class="h-4 w-4" />
                            {{ __('Share') }}
                        </button>
                        @if ($photos->count() > 1)
                            <button type="button" data-photo-open="0"
                                    class="flex items-center gap-2 rounded-full bg-white/90 px-4 py-2 text-sm font-medium text-stone-900 shadow transition hover:bg-white">
                                <x-site-icon name="photo" class="h-4 w-4" />
                                {{ __('Show all photos') }} ({{ $photos->count() }})
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @include('site.properties.partials.photo-lightbox')
    @else
        {{-- Zonder foto's: nette titelbalk --}}
        <div class="mx-auto max-w-7xl px-4 pt-8 sm:px-6">
            <div class="flex items-start justify-between gap-4">
                <h1 class="text-2xl font-semibold text-stone-900 sm:text-3xl">{{ $property->localized('name') }}</h1>
                <button onclick="share_dialog.showModal()"
                        class="flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium underline transition hover:bg-stone-100">
                    <x-site-icon name="share" class="h-4 w-4" />
                    {{ __('Share') }}
                </button>
            </div>
        </div>
    @endif

    @include('site.properties.partials.share-modal')
</section>
