@props(['max' => 16, 'petsAllowed' => true, 'maxBabies' => null, 'maxPets' => null])
@php
    // Without an accommodation (searching on the homepage): the limits or the default.
    $maxBabies ??= \App\Models\Property::master()->maxBabies();
    $maxPets ??= (int) \App\Models\Property::master()->max_pets;
@endphp

{{-- Guest picker (Airbnb style): hidden inputs + popover. The trigger button
     ([data-guests-field] with [data-guests-text]) is in the page itself. --}}
<input type="hidden" name="guests" value="{{ min((int) request('guests', 2), $max) }}">
<input type="hidden" name="adults" value="{{ min((int) request('adults', request('guests', 2)), $max) }}">
<input type="hidden" name="children" value="{{ (int) request('children', 0) }}">
<input type="hidden" name="infants" value="{{ (int) request('infants', 0) }}">
<input type="hidden" name="pets" value="{{ (int) request('pets', 0) }}">

<div data-guests-popover
     data-max="{{ $max }}"
     data-pets="{{ $petsAllowed ? 1 : 0 }}"
     data-max-babies="{{ $maxBabies }}"
     data-max-pets="{{ $petsAllowed ? $maxPets : 0 }}"
     data-t-guest="{{ __('guest') }}" data-t-guests="{{ __('guests') }}"
     data-t-baby="{{ __('infant') }}" data-t-babies="{{ __("infants") }}"
     data-t-pet="{{ __('pet') }}" data-t-pets="{{ __('pets') }}"
     {{ $attributes->merge(['class' => 'absolute z-50 mt-2 hidden rounded-2xl border border-stone-200 bg-white p-5 shadow-2xl']) }}>
    @foreach ([
        ['key' => 'adults', 'title' => __('Adults'), 'under' => __('Ages 13 or above')],
        ['key' => 'children', 'title' => __('Children'), 'under' => __('Ages 2 – 12')],
        ['key' => 'infants', 'title' => __("Infants"), 'under' => __('Under 2')],
        ['key' => 'pets', 'title' => __('Pets'), 'under' => null],
    ] as $row)
        <div class="flex items-center justify-between py-3 {{ $loop->last ? '' : 'border-b border-stone-100' }}">
            <div class="pr-6 text-left">
                <p class="font-medium text-stone-900">{{ $row['title'] }}</p>
                @if ($row['under'])
                    <p class="text-sm text-stone-500">{{ $row['under'] }}</p>
                @endif
            </div>
            <div class="flex items-center gap-3" data-counter="{{ $row['key'] }}">
                <button type="button" data-step="-1"
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-stone-300 text-stone-600 transition hover:border-stone-800 disabled:cursor-not-allowed disabled:opacity-30">−</button>
                <span data-value class="w-6 text-center text-sm"></span>
                <button type="button" data-step="1"
                        class="flex h-8 w-8 items-center justify-center rounded-full border border-stone-300 text-stone-600 transition hover:border-stone-800 disabled:cursor-not-allowed disabled:opacity-30">+</button>
            </div>
        </div>
    @endforeach

    @unless ($petsAllowed)
        <p class="pt-3 text-left text-sm text-stone-500">{{ __('Pets are not allowed.') }}</p>
    @endunless

    <div class="flex justify-end pt-3">
        <button type="button" data-guests-close class="text-sm font-semibold underline hover:text-rose-600">
            {{ __('Close') }}
        </button>
    </div>
</div>
