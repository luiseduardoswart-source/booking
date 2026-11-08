<x-site-layout :title="__('Contact')" :metaDescription="__('Get in touch with us — we respond quickly.')">
    <div class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-bold text-stone-900">{{ __('Contact') }}</h1>
        <p class="mt-2 text-stone-600">{{ __('Questions about an accommodation or your booking? Send us a message.') }}</p>

        @if (session('contact_sent'))
            <div class="mt-6 rounded-lg bg-green-50 p-4 text-green-800">
                {{ __('Thank you for your message! We will get back to you as soon as possible.') }}
            </div>
        @else
            <form action="{{ route('contact.store') }}" method="post" class="mt-8 space-y-4">
                @csrf
                {{-- Honeypot tegen spam --}}
                <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off">

                @if ($errors->any())
                    <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>
                @endif

                <label class="block">
                    <span class="text-sm font-medium text-stone-700">{{ __('Name') }} *</span>
                    <input type="text" name="name" required value="{{ old('name') }}"
                           class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-stone-700">{{ __('Email address') }} *</span>
                    <input type="email" name="email" required value="{{ old('email') }}"
                           class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-stone-700">{{ __('Message') }} *</span>
                    <textarea name="message" rows="5" required
                              class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">{{ old('message') }}</textarea>
                </label>
                <button type="submit"
                        class="rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                    {{ __('Send message') }}
                </button>
            </form>
        @endif
    </div>
</x-site-layout>
