<x-site-layout :title="__('Test payment')">
    <div class="mx-auto max-w-lg px-4 py-16 sm:px-6">
        <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <strong>{{ __('Test mode') }}</strong> —
            {{ __('This is a mock payment page to test the flow. Nothing is charged.') }}
        </div>

        <div class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            <h1 class="text-xl font-semibold text-stone-900">{{ __('Payment') }} {{ $booking->booking_number }}</h1>
            <p class="mt-1 text-stone-600">{{ $payment->description }}</p>

            <p class="mt-6 text-3xl font-bold text-stone-900">
                {{ \App\Support\Money::format($payment->amount_cents, $payment->booking->currency) }}
            </p>

            <p class="mt-8 text-sm font-medium text-stone-700">{{ __('Choose the outcome of this payment:') }}</p>

            <div class="mt-3 space-y-2">
                <a href="{{ URL::signedRoute('payment.test.process', [$payment, 'paid']) }}"
                   class="flex w-full items-center justify-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                    {{ __('Payment successful') }}
                </a>
                <a href="{{ URL::signedRoute('payment.test.process', [$payment, 'failed']) }}"
                   class="flex w-full items-center justify-center rounded-xl border border-stone-300 px-4 py-3 font-medium text-stone-700 transition hover:bg-stone-50">
                    {{ __('Payment failed') }}
                </a>
                <a href="{{ URL::signedRoute('payment.test.process', [$payment, 'cancelled']) }}"
                   class="flex w-full items-center justify-center rounded-xl border border-stone-300 px-4 py-3 font-medium text-stone-700 transition hover:bg-stone-50">
                    {{ __('Payment cancelled') }}
                </a>
            </div>
        </div>
    </div>
</x-site-layout>
