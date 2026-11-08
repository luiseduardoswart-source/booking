{{-- Lightbox with all photos (shared by all layouts; expects $property and $fotos) --}}
<dialog id="photo_dialog" data-property="{{ $property->id }}" class="m-auto w-full max-w-3xl rounded-2xl p-0 backdrop:bg-black/70">
    <div class="sticky top-0 flex items-center justify-between bg-white px-4 py-3">
        <strong>{{ $property->localized('name') }}</strong>
        <button onclick="photo_dialog.close()" class="flex items-center gap-1.5 rounded-full px-3 py-1 hover:bg-stone-100">
            <x-site-icon name="x-mark" class="h-4 w-4" /> {{ __('Close') }}
        </button>
    </div>
    <div class="space-y-2 p-4 pt-0">
        @foreach ($photos as $photo)
            <div class="relative">
                <img src="{{ $photo->getUrl('large') }}" alt="{{ $property->localized('name') }}" data-photo-track="{{ $photo->id }}" class="w-full rounded-xl" loading="lazy">
                <x-photo-label :photo="$photo" />
            </div>
        @endforeach
    </div>
</dialog>
