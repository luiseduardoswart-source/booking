@props(['unavailableUrl' => null])

{{-- Double-month calendar (filled by app.js). Works inside the surrounding
     form on the hidden inputs "arrival" and "departure". --}}
<div data-calendar-popover
     @if ($unavailableUrl) data-unavailable-url="{{ $unavailableUrl }}" @endif
     data-locale="{{ app()->getLocale() }}"
     data-text-clear="{{ __('Clear dates') }}"
     data-text-choose="{{ __('Select date') }}"
     {{ $attributes->merge(['class' => 'absolute z-50 mt-2 hidden w-[88vw] rounded-2xl border border-stone-200 bg-white p-4 shadow-2xl sm:w-[42rem] sm:max-w-[92vw] sm:p-5']) }}></div>
