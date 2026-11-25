@if (filled($booking->property?->registration_number))
    {{ __('Registration number') }}: {{ $booking->property->registration_number }}<br>
@endif
