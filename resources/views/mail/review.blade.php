@php \Carbon\Carbon::setLocale(app()->getLocale()); \Carbon\CarbonImmutable::setLocale(app()->getLocale()); @endphp
<x-mail::message>
# {{ __('How was your stay?') }}

{{ __('Dear :name,', ['name' => $guestName]) }}

{{ __('We hope you enjoyed your stay at :accommodation!', ['accommodation' => $property->localized('name')]) }}

@if (!empty($extraText))
{{ $extraText }}

@endif
{{ __('Would you take a few minutes to leave a review? It helps future guests and us enormously.') }}

<x-mail::button :url="$reviewUrl">
{{ __('Leave a review') }}
</x-mail::button>

{{ __('Thank you and see you next time!') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
