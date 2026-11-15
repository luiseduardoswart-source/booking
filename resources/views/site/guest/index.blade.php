{{-- My bookings: the bookings of the logged-in guest, or the form to ask for a login link. --}}
<x-site-layout :title="__('My bookings')">
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 class="text-2xl font-bold text-stone-900">{{ __('My bookings') }}</h1>

        @if (session('guest_status'))
            <p class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('guest_status') }}</p>
        @endif
        @if (session('guest_error'))
            <p class="mt-4 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">{{ session('guest_error') }}</p>
        @endif

        @if ($email === null)
            <div class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                <p class="text-stone-600">{{ __('Fill in the email address you booked with. We will send you a login link; no password needed.') }}</p>
                <form method="POST" action="{{ route('guest.link') }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                    @csrf
                    <label for="guest_email" class="sr-only">{{ __('Email address') }}</label>
                    <input id="guest_email" type="email" name="email" required autocomplete="email" value="{{ old('email') }}"
                           placeholder="{{ __('Email address') }}"
                           class="flex-1 rounded-xl border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-5 py-2.5 font-semibold text-white hover:from-rose-700 hover:to-pink-700">
                        <x-site-icon name="envelope" class="h-5 w-5" />
                        {{ __('Send login link') }}
                    </button>
                </form>
                @error('email')
                    <p class="mt-2 text-sm text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        @else
            <p class="mt-2 text-sm text-stone-500">{{ __('Logged in as :email', ['email' => $email]) }}</p>

            <div class="mt-6 space-y-3">
                @forelse ($bookings as $booking)
                    <a href="{{ route('guest.booking', $booking) }}" class="flex items-center justify-between gap-4 rounded-2xl border border-stone-200 bg-white p-5 shadow-sm transition hover:border-rose-300">
                        <div>
                            <p class="font-semibold text-stone-900">{{ $booking->property?->localized('name') }}</p>
                            <p class="text-sm text-stone-600">
                                {{ $booking->arrival_date->translatedFormat('j M Y') }} – {{ $booking->departure_date->translatedFormat('j M Y') }}
                                · {{ $booking->booking_number }} · {{ __($booking->status->label()) }}
                            </p>
                        </div>
                        <x-site-icon name="chevron-right" class="h-5 w-5 shrink-0 text-stone-400" />
                    </a>
                @empty
                    <p class="text-stone-600">{{ __('There are no bookings with this email address.') }}</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('guest.logout') }}" class="mt-8 flex flex-wrap gap-3">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:border-rose-400 hover:text-rose-600">
                    <x-site-icon name="log-out" class="h-5 w-5" />
                    {{ __('Log out') }}
                </button>
                <button type="submit" name="everywhere" value="1" class="inline-flex items-center gap-2 rounded-xl border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 hover:border-rose-400 hover:text-rose-600">
                    <x-site-icon name="x-mark" class="h-5 w-5" />
                    {{ __('Log out on all devices') }}
                </button>
            </form>
        @endif
    </div>
</x-site-layout>
