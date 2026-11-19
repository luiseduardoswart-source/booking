{{-- Title + photo mosaic (default layout). Layout templates can override this
     file for their own photo presentation; the lightbox and
     share modal are separate partials and stay shared. Expects $property and $photos
     ($property->gallery(): the main photo first, videos in between). --}}
<div class="mx-auto max-w-7xl px-4 pt-8 sm:px-6">
    {{-- Title above the photos, like Airbnb — with a share button on the right --}}
    <div class="flex items-start justify-between gap-4">
        <h1 class="text-2xl font-semibold text-stone-900 sm:text-3xl">{{ $property->localized('name') }}</h1>
        <button onclick="share_dialog.showModal()"
                class="flex shrink-0 items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium underline transition hover:bg-stone-100">
            <x-site-icon name="share" class="h-4 w-4" />
            {{ __('Share') }}
        </button>
    </div>
    <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-stone-600 underline-offset-2">
        @if ($property->approved_reviews_count > 0)
            <span class="flex items-center gap-1 font-medium text-stone-900">
                <span class="text-base">★</span>
                {{ number_format($property->approved_reviews_avg_rating, 1, ',', '') }}
            </span>
            <a href="#reviews" class="underline">{{ trans_choice('{1} 1 review|[2,*] :count reviews', $property->approved_reviews_count) }}</a>
            <span>·</span>
        @endif
        @if ($property->city)<span class="font-medium underline">{{ $property->city }}, {{ \App\Support\Countries::name($property->country) }}</span>@endif
    </p>

    {{-- Photo mosaic: 1 large + 4 small --}}
    @if ($photos->isNotEmpty())
        <div class="relative mt-4">
            <div class="grid gap-2 overflow-hidden rounded-2xl sm:grid-cols-4 sm:grid-rows-2">
                <button type="button" data-photo-open="0" class="sm:col-span-2 sm:row-span-2">
                    <x-property-media :media="$photos->first()" conversion="large" :alt="$property->localized('name')"
                         class="aspect-[4/3] h-full w-full cursor-pointer object-cover transition hover:brightness-90" />
                </button>
                @foreach ($photos->skip(1)->take(4) as $index => $photo)
                    <button type="button" data-photo-open="{{ $index }}" class="hidden sm:block">
                        <x-property-media :media="$photo" conversion="thumb" :alt="$property->localized('name')"
                             class="aspect-[4/3] h-full w-full cursor-pointer object-cover transition hover:brightness-90" />
                    </button>
                @endforeach
            </div>
            @if ($photos->count() > 1)
                <button type="button" data-photo-open="0"
                        class="absolute bottom-4 right-4 flex items-center gap-2 rounded-lg border border-stone-800 bg-white px-3 py-1.5 text-sm font-medium shadow transition hover:bg-stone-100">
                    <x-site-icon name="photo" class="h-4 w-4" />
                    {{ __('Show all photos') }} ({{ $photos->count() }})
                </button>
            @endif
        </div>

        @include('site.properties.partials.photo-lightbox')
    @endif

    @include('site.properties.partials.share-modal')
</div>
