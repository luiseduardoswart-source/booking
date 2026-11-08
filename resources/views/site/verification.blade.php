<x-site-layout :title="__('Identity verification')" :property="$property">
    <div class="mx-auto max-w-2xl px-4 py-16 text-center">
        <div class="rounded-2xl border border-stone-200 bg-white p-10 shadow-sm">
            @if ($status === \App\Enums\IdentityStatus::Verified)
                <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-rose-50">
                    <x-site-icon name="check-circle" class="h-10 w-10 text-rose-600" />
                </span>
                <h1 class="mt-4 text-2xl font-bold text-stone-900">{{ __('Your identity has been verified!') }}</h1>
                <p class="mt-3 text-stone-600">{{ __('Thank you — no further action is needed.') }}</p>
            @elseif ($status === \App\Enums\IdentityStatus::Failed)
                <h1 class="text-2xl font-bold text-stone-900">{{ __('Verification failed') }}</h1>
                <p class="mt-3 text-stone-600">{{ __('Try again via the link in your confirmation email, or contact us.') }}</p>
            @else
                <h1 class="text-2xl font-bold text-stone-900">{{ __('Verification in progress') }}</h1>
                <p class="mt-3 text-stone-600">{{ __('Your verification is being processed. This usually takes only a few minutes; no further action is needed.') }}</p>
            @endif
            <a href="{{ route('home') }}" class="mt-6 inline-block text-rose-600 hover:underline">
                {{ __('Back to the homepage') }}
            </a>
        </div>
    </div>
</x-site-layout>
