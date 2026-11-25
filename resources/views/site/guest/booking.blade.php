{{-- One booking for the guest: the stay, what is left to pay, the ID check and the documents. --}}
<x-site-layout :title="__('Booking :number', ['number' => $booking->booking_number])" :property="$booking->property">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        @if ($loggedIn)
            <a href="{{ route('guest.bookings') }}" class="text-sm font-medium text-rose-600 hover:underline">← {{ __('My bookings') }}</a>
        @endif

        <h1 class="mt-2 text-2xl font-bold text-stone-900">{{ $booking->property?->localized('name') }}</h1>
        <p class="mt-1 text-stone-600">{{ __('Booking :number', ['number' => $booking->booking_number]) }} · {{ __($booking->status->label()) }}</p>

        @if (session('guest_status'))
            <p class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('guest_status') }}</p>
        @endif

        <dl class="mt-6 grid gap-4 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:grid-cols-3">
            <div>
                <dt class="text-sm text-stone-500">{{ __('Check-in') }}</dt>
                <dd class="font-medium text-stone-900">{{ $booking->arrival_date->translatedFormat('l j F Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-stone-500">{{ __('Check-out') }}</dt>
                <dd class="font-medium text-stone-900">{{ $booking->departure_date->translatedFormat('l j F Y') }}</dd>
            </div>
            <div>
                <dt class="text-sm text-stone-500">{{ __('Guests') }}</dt>
                <dd class="font-medium text-stone-900">{{ $booking->guests }}</dd>
            </div>
            <div>
                <dt class="text-sm text-stone-500">{{ __('Total') }}</dt>
                <dd class="font-medium text-stone-900">{{ \App\Support\Money::format($booking->total_cents, $booking->currency) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-stone-500">{{ __('Payment') }}</dt>
                <dd class="font-medium text-stone-900">{{ $booking->payment_status->getLabel() }}</dd>
            </div>
            @if ($outstanding > 0)
                <div>
                    <dt class="text-sm text-stone-500">{{ __('Still to pay') }}</dt>
                    <dd class="font-medium text-stone-900">{{ \App\Support\Money::format($outstanding, $booking->currency) }}</dd>
                </div>
            @endif
            @if (filled($booking->guest_phone))
                <div>
                    <dt class="text-sm text-stone-500">{{ __('Phone number') }}</dt>
                    <dd class="font-medium text-stone-900">{{ $booking->guest_phone }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-4 flex flex-wrap gap-3">
            @if ($payLink)
                <a href="{{ $payLink }}" class="inline-flex items-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-5 py-2.5 font-semibold text-white hover:from-rose-700 hover:to-pink-700">
                    <x-site-icon name="credit-card" class="h-5 w-5" />
                    {{ __('Pay now') }}
                </a>
            @endif
            @if ($booking->identityMissing() && $booking->status !== \App\Enums\BookingStatus::Cancelled)
                <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('identity.start', $booking) }}" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-5 py-2.5 font-semibold text-stone-800 hover:border-rose-400 hover:text-rose-600">
                    <x-site-icon name="identification" class="h-5 w-5" />
                    {{ __('Verify your identity') }}
                </a>
            @endif
            @if ($booking->guestRegistrationMissing())
                <a href="{{ $booking->signedLink('guest-registration.show') }}" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-5 py-2.5 font-semibold text-stone-800 hover:border-rose-400 hover:text-rose-600">
                    <x-site-icon name="users" class="h-5 w-5" />
                    {{ __('Register your guests') }}
                </a>
            @endif
        </div>
        @if ($booking->identityMissing() && $booking->identityDeadline() && $booking->status !== \App\Enums\BookingStatus::Cancelled)
            <p class="mt-2 text-sm text-stone-600">{{ __('Please do this by :date at the latest.', ['date' => $booking->identityDeadline()->translatedFormat('l j F Y')]) }}</p>
        @endif

        {{-- Booked without a phone number: add it here (the confirmation email links to #phone). --}}
        @if ($booking->phoneCanBeAdded())
            <form id="phone" method="post" action="{{ route('guest.phone', $booking) }}" class="mt-8 scroll-mt-24 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                @csrf
                <label for="guest-phone" class="font-semibold text-stone-900">{{ __('Phone number') }}</label>
                <p class="mt-1 text-sm text-stone-600">{{ __('Add your phone number so we can reach you about your arrival, for example via WhatsApp.') }}</p>
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <input type="tel" id="guest-phone" name="phone" value="{{ old('phone') }}" required maxlength="50" autocomplete="tel"
                           class="w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    <button type="submit" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-stone-300 px-5 py-2.5 font-semibold text-stone-800 hover:border-rose-400 hover:text-rose-600">
                        <x-site-icon name="check-circle" class="h-5 w-5" />
                        {{ __('Save phone number') }}
                    </button>
                </div>
                @error('phone')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </form>
        @endif

        <h2 class="mt-10 text-lg font-semibold text-stone-900">{{ __('Documents') }}</h2>
        @if ($documents->isEmpty())
            <p class="mt-2 text-stone-600">{{ __('Your documents appear here once your booking has been confirmed.') }}</p>
        @else
            <ul class="mt-3 divide-y divide-stone-200 rounded-2xl border border-stone-200 bg-white shadow-sm">
                @foreach ($documents as $document)
                    <li>
                        <a href="{{ $document['url'] }}" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-stone-50">
                            <span class="text-stone-800">{{ $document['label'] }}</span>
                            <span class="inline-flex items-center gap-2 text-sm font-medium text-rose-600">
                                <x-site-icon name="arrow-down-tray" class="h-5 w-5" />
                                {{ __('Download') }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @unless ($loggedIn)
            <p class="mt-10 text-sm text-stone-500">
                {{ __('Want to see all your bookings?') }}
                <a href="{{ route('guest.bookings') }}" class="font-medium text-rose-600 hover:underline">{{ __('Log in to My bookings') }}</a>
            </p>
        @endunless
    </div>
</x-site-layout>
