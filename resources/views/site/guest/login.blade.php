{{-- The link from the login email: a button, so a mail program that opens links to scan them does not use it up. --}}
<x-site-layout :title="__('My bookings')">
    <div class="mx-auto max-w-xl px-4 py-16 text-center">
        <div class="rounded-2xl border border-stone-200 bg-white p-10 shadow-sm">
            <h1 class="text-2xl font-bold text-stone-900">{{ __('Log in to My bookings') }}</h1>
            <form method="POST" action="{{ route('guest.login.use', ['token' => $token]) }}" class="mt-6">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white hover:from-rose-700 hover:to-pink-700">
                    <x-site-icon name="log-in" class="h-5 w-5" />
                    {{ __('Log in') }}
                </button>
            </form>
        </div>
    </div>
</x-site-layout>
