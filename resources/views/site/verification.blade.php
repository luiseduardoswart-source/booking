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
                <p class="mt-3 text-stone-600">{{ __('The check did not succeed. You can try again right away, or contact us.') }}</p>
                <div>
                    <a href="{{ $retryUrl }}"
                       class="mt-6 inline-flex items-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                        <x-site-icon name="arrow-path" />
                        {{ __('Try again') }}
                    </a>
                </div>
            @else
                <h1 class="text-2xl font-bold text-stone-900">{{ __('Verification in progress') }}</h1>
                <p class="mt-3 text-stone-600">{{ __('Your verification is being processed. This usually takes only a few minutes; no further action is needed.') }}</p>
            @endif
            <a href="{{ \App\Support\LocalizedUrl::home() }}" class="mt-6 inline-block text-rose-600 hover:underline">
                {{ __('Back to the homepage') }}
            </a>
        </div>
    </div>
</x-site-layout>
