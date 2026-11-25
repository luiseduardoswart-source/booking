{{-- Where the authorities require it: every traveller registered before arrival (see GuestRegistrationController). --}}
@if (!empty($guestRegistrationUrl))
## {{ __('Register your guests') }}

{{ __('The law requires us to register every guest with the authorities. Please fill in the details of everyone who stays before you arrive.') }}

<x-mail::button :url="$guestRegistrationUrl">
{{ __('Register your guests') }}
</x-mail::button>

@endif