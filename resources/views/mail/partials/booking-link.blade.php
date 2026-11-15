{{-- In every guest email: the booking and its documents online (signed link, see GuestAccess). --}}
@if (!empty($bookingUrl))
{{ __('Your booking, documents and payments are always online:') }} [{{ __('View your booking') }}]({{ $bookingUrl }})

@endif
