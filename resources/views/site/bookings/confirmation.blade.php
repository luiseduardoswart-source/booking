<x-site-layout :title="__('Booking received')" :property="$booking->property">
    <div class="mx-auto max-w-2xl px-4 py-16 text-center">
        <div class="rounded-2xl border border-stone-200 bg-white p-10 shadow-sm">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-rose-50">
                <x-site-icon name="check-circle" class="h-10 w-10 text-rose-600" />
            </span>
            <h1 class="mt-4 text-2xl font-bold text-stone-900">{{ __('Thank you for your booking!') }}</h1>
            <p class="mt-3 text-stone-600">
                {!! __('Your reservation <strong>:number</strong> for <strong>:accommodation</strong> has been received. You will receive a confirmation by email at :email.', [
                    'number' => e($booking->booking_number),
                    'accommodation' => e($booking->property->localized('name')),
                    'email' => e($booking->guest_email),
                ]) !!}
            </p>
            @if ($booking->payment_status === \App\Enums\PaymentStatus::Unpaid)
                <p class="mt-3 text-stone-600">
                    {!! __($booking->paysInFull()
                        ? 'We will send you a payment link for the amount of <strong>:amount</strong> as soon as possible.'
                        : 'We will send you a payment link for the down payment of <strong>:amount</strong> as soon as possible.', [
                        'amount' => \App\Support\Money::format($booking->down_payment_cents, $booking->currency),
                    ]) !!}
                </p>
            @endif
            <div>
                <a href="{{ \App\Support\GuestAccess::bookingUrl($booking) }}" class="mt-6 inline-flex items-center gap-2 rounded-xl border border-stone-300 px-5 py-2.5 font-semibold text-stone-800 hover:border-rose-400 hover:text-rose-600">
                    <x-site-icon name="chevron-right" class="h-5 w-5" />
                    {{ __('View your booking') }}
                </a>
            </div>
            <a href="{{ \App\Support\LocalizedUrl::home() }}" class="mt-6 inline-block text-rose-600 hover:underline">
                {{ __('Back to the homepage') }}
            </a>
        </div>
    </div>
</x-site-layout>
