{{-- Alle faciliteiten per categorie (Airbnb-stijl; verwacht $property met geladen amenities) --}}
<dialog id="amenities_dialog" class="m-auto max-h-[90vh] w-full max-w-2xl rounded-3xl p-0 backdrop:bg-black/60">
    <div class="sticky top-0 z-10 flex justify-end bg-white px-4 pt-4">
        <button type="button" onclick="amenities_dialog.close()" aria-label="{{ __('Close') }}"
                class="flex h-8 w-8 items-center justify-center rounded-full transition hover:bg-stone-100">
            <x-site-icon name="x-mark" class="h-4 w-4" />
        </button>
    </div>
    <div class="px-6 pb-8 sm:px-8">
        <h2 class="text-2xl font-semibold text-stone-900">{{ __('What this place offers') }}</h2>

        @foreach (\App\Models\Amenity::perCategory($property->amenities) as $category => $amenities)
            <h3 class="mt-8 text-lg font-semibold text-stone-900">{{ $category }}</h3>
            <ul class="divide-y divide-stone-200">
                @foreach ($amenities as $amenity)
                    <li class="flex items-center gap-4 py-5 text-stone-700">
                        <x-site-icon :name="$amenity->iconName()" class="h-6 w-6 shrink-0 text-stone-700" />
                        {{ $amenity->localizedName() }}
                    </li>
                @endforeach
            </ul>
        @endforeach
    </div>
</dialog>
