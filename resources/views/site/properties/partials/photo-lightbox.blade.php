{{-- Photo viewer (shared by all layouts; expects $property and $photos): one
     photo or video at a time, full screen, with its description, arrows, a
     counter and a row of thumbnails. Opened by any element with
     data-photo-open="index"; the behaviour is in resources/js/app.js
     (initPhotoViewer). Hidden slides load their image only when shown
     (loading="lazy" inside display:none). --}}
<dialog id="photo_dialog" data-property="{{ $property->id }}" data-photo-viewer
        aria-label="{{ $property->localized('name') }}"
        class="m-0 h-dvh max-h-none w-screen max-w-none overflow-hidden bg-black p-0 text-white backdrop:bg-black">
    <div class="flex h-full flex-col">
        {{-- Description, counter and close --}}
        <div class="flex items-start gap-4 px-4 pb-3 pt-4 sm:px-8">
            <p data-photo-caption class="min-h-5 flex-1 text-sm leading-snug text-white/90"></p>
            <span data-photo-counter class="shrink-0 pt-0.5 text-sm tabular-nums text-white/80">1 / {{ $photos->count() }}</span>
            <button type="button" data-photo-close
                    class="flex shrink-0 items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-sm transition hover:bg-white/20">
                <x-site-icon name="x-mark" class="h-4 w-4" /> {{ __('Close') }}
            </button>
        </div>

        {{-- The photo, with an arrow on each side --}}
        <div class="relative min-h-0 flex-1 border-y border-white/10">
            @foreach ($photos as $index => $photo)
                @php $label = \App\Models\Property::photoLabel($photo); @endphp
                <figure data-photo-slide data-index="{{ $index }}" data-photo-track="{{ $photo->id }}" data-caption="{{ $label }}"
                        @if ($index > 0) hidden @endif
                        class="absolute inset-0 m-0 flex items-center justify-center px-2 py-2 sm:px-20">
                    <x-property-media :media="$photo" conversion="large" :alt="$label ?? $property->localized('name')" :controls="true"
                                      loading="lazy" class="max-h-full max-w-full object-contain" />
                </figure>
            @endforeach

            @if ($photos->count() > 1)
                <button type="button" data-photo-prev aria-label="{{ __('Previous photo') }}"
                        class="absolute left-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-stone-900 shadow transition hover:bg-white sm:left-6">
                    <x-site-icon name="chevron-left" class="h-5 w-5" />
                </button>
                <button type="button" data-photo-next aria-label="{{ __('Next photo') }}"
                        class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-stone-900 shadow transition hover:bg-white sm:right-6">
                    <x-site-icon name="chevron-right" class="h-5 w-5" />
                </button>
            @endif
        </div>

        {{-- Thumbnails: jump straight to a photo. No scrollbar: the arrows at
             either end scroll the row and disappear at its start and end
             (a finger can still swipe the row). --}}
        @if ($photos->count() > 1)
            <div class="flex items-center gap-1 px-2 py-2 sm:gap-2 sm:px-4 sm:py-3">
                <button type="button" data-photo-thumbs-prev aria-label="{{ __('Show earlier thumbnails') }}" disabled
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20 disabled:invisible">
                    <x-site-icon name="chevron-left" class="h-5 w-5" />
                </button>
                <div class="flex min-w-0 flex-1 gap-2 overflow-x-auto p-1" data-photo-thumbs>
                    @foreach ($photos as $index => $photo)
                        <button type="button" data-photo-thumb data-index="{{ $index }}"
                                aria-label="{{ __('Show photo :number', ['number' => $index + 1]) }}"
                                class="relative h-14 w-20 shrink-0 overflow-hidden rounded-md opacity-60 ring-white transition hover:opacity-100 data-[active]:opacity-100 data-[active]:ring-2 sm:h-20 sm:w-28">
                            <x-property-media :media="$photo" conversion="thumb" alt="" loading="lazy" class="h-full w-full object-cover" />
                        </button>
                    @endforeach
                </div>
                <button type="button" data-photo-thumbs-next aria-label="{{ __('Show later thumbnails') }}" disabled
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20 disabled:invisible">
                    <x-site-icon name="chevron-right" class="h-5 w-5" />
                </button>
            </div>
        @endif
    </div>
</dialog>
