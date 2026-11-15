<x-mail::message>
# {{ __('Your login link') }}

{{ __('Use the button below to see your bookings and documents. The link is valid for :minutes minutes and works once.', ['minutes' => $minutes]) }}

<x-mail::button :url="$url">
{{ __('Log in to My bookings') }}
</x-mail::button>

{{ __('Did you not ask for this? Then you can ignore this email.') }}

{{ __('Kind regards,') }}<br>
{{ $company }}
</x-mail::message>
