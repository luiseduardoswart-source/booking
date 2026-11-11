@props(['property', 'totalCents' => null, 'nights' => null, 'query' => []])

<a href="{{ route('properties.show', array_merge(['property' => $property->slugFor()], $query)) }}" class="group">
    <div class="aspect-[4/3] overflow-hidden rounded-2xl bg-stone-100">
        @if ($photo = $property->coverPhotoUrl('thumb'))
            <img src="{{ $photo }}" alt="{{ $property->localized('name') }}"
                 class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
        @else
            <div class="flex h-full items-center justify-center text-stone-400">{{ __('No photo') }}</div>
        @endif
    </div>
    <div class="mt-3">
        <div class="flex items-baseline justify-between gap-2">
            <h3 class="font-semibold text-stone-900">{{ $property->localized('name') }}</h3>
            @if (($property->approved_reviews_count ?? 0) > 0)
                <span class="flex shrink-0 items-center gap-1 text-sm text-stone-700">
                    ★ {{ number_format($property->approved_reviews_avg_rating, 1, ',', '') }}
                </span>
            @endif
        </div>
        <p class="text-sm text-stone-500">
            {{ $property->typeName() }}@if ($property->city) · {{ $property->city }}@endif
            · {{ __('max. :count guests', ['count' => $property->max_guests]) }}
        </p>
        <p class="mt-1 text-sm text-stone-900">
            @if ($totalCents !== null && $nights !== null)
                <span class="font-semibold">{{ \App\Support\Money::format($totalCents, $property->priceCurrency()) }}</span>
                <span class="text-stone-500">{{ trans_choice('{1} total for 1 night|[2,*] total for :count nights', $nights) }}</span>
            @else
                <span class="font-semibold">{{ \App\Support\Money::format($property->basePrice(), $property->priceCurrency()) }}</span>
                <span class="text-stone-500">{{ __('per night') }}</span>
            @endif
        </p>
    </div>
</a>
