{{-- Filter bar (in the search form; every change filters immediately).
     Expects $typeOptions, $amenityOptions and $filters. --}}
@php $filterClass = 'rounded-full border-stone-300 py-2 pl-4 pr-9 text-sm focus:border-rose-500 focus:ring-rose-500'; @endphp
<div class="flex flex-wrap items-center gap-2">
    <select name="price_max" onchange="this.form.submit()" class="{{ $filterClass }}">
        <option value="">{{ __('Price per night: all') }}</option>
        @foreach ($priceSteps ?? [100, 150, 200, 300, 500] as $amount)
            <option value="{{ $amount }}" @selected(($filters['price_max'] ?? null) == $amount)>{{ __('Up to :amount per night', ['amount' => \App\Support\Money::compact($amount * 100)]) }}</option>
        @endforeach
    </select>

    @if (count($typeOptions) > 1)
        <select name="type" onchange="this.form.submit()" class="{{ $filterClass }}">
            <option value="">{{ __('Type: all') }}</option>
            @foreach ($typeOptions as $slug => $name)
                <option value="{{ $slug }}" @selected(($filters['type'] ?? null) === $slug)>{{ $name }}</option>
            @endforeach
        </select>
    @endif

    @if ($amenityOptions !== [])
        <select name="amenity" onchange="this.form.submit()" class="{{ $filterClass }}">
            <option value="">{{ __('Amenity: all') }}</option>
            @foreach ($amenityOptions as $id => $name)
                <option value="{{ $id }}" @selected(($filters['amenity'] ?? null) == $id)>{{ $name }}</option>
            @endforeach
        </select>
    @endif

    <select name="bedrooms" onchange="this.form.submit()" class="{{ $filterClass }}">
        <option value="">{{ __('Bedrooms: all') }}</option>
        @foreach ([1, 2, 3, 4] as $count)
            <option value="{{ $count }}" @selected(($filters['bedrooms'] ?? null) == $count)>{{ $count }}+</option>
        @endforeach
    </select>

    <select name="bathrooms" onchange="this.form.submit()" class="{{ $filterClass }}">
        <option value="">{{ __('Bathrooms: all') }}</option>
        @foreach ([1, 2, 3] as $count)
            <option value="{{ $count }}" @selected(($filters['bathrooms'] ?? null) == $count)>{{ $count }}+</option>
        @endforeach
    </select>

    @if (array_filter([$filters['type'] ?? null, $filters['price_max'] ?? null, $filters['amenity'] ?? null, $filters['bedrooms'] ?? null, $filters['bathrooms'] ?? null]) !== [])
        <a href="{{ route('home') }}" class="text-sm font-medium underline hover:text-rose-600">{{ __('Clear filters') }}</a>
    @endif
</div>
