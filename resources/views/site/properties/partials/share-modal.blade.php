{{-- Deelmodal (Airbnb-stijl; gedeeld door alle lay-outs; verwacht $property) --}}
@php
    $shareUrl = route('properties.show', $property->slugFor());
    $shareTitle = $property->localized('name');
@endphp
<dialog id="share_dialog" class="m-auto w-full max-w-xl rounded-3xl p-0 backdrop:bg-black/60">
    <div class="p-6 sm:p-8">
        <button onclick="share_dialog.close()"
                class="ml-auto flex h-8 w-8 items-center justify-center rounded-full transition hover:bg-stone-100">
            <x-site-icon name="x-mark" class="h-4 w-4" />
        </button>

        <h2 class="mt-2 text-2xl font-semibold text-stone-900">{{ __('Share this place') }}</h2>

        <div class="mt-6 flex items-center gap-4">
            @if ($thumb = $property->coverPhotoUrl('thumb'))
                <img src="{{ $thumb }}" alt="{{ $property->localized('name') }}" class="h-16 w-16 rounded-xl object-cover">
            @endif
            <p class="text-stone-700">
                {{ $property->typeName() }} · {{ $property->city }}@if ($property->approved_reviews_count > 0) · ★{{ number_format($property->approved_reviews_avg_rating, 2, ',', '') }}@endif
                @if ($property->bedrooms) · {{ $property->bedrooms }} {{ __('bedrooms') }}@endif
                @if ($property->beds) · {{ $property->beds }} {{ __('beds') }}@endif
                @if ($property->sofa_beds) · {{ trans_choice(':count sofa bed|:count sofa beds', $property->sofa_beds) }}@endif
                @if ($property->bathrooms) · {{ $property->bathrooms }} {{ __('bathrooms') }}@endif
            </p>
        </div>

        @php
            $shareButtonClass = 'flex items-center gap-3 rounded-xl border border-stone-300 px-4 py-3.5 font-medium text-stone-900 transition hover:bg-stone-50';
        @endphp
        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <button type="button" data-share-copy data-url="{{ $shareUrl }}" class="{{ $shareButtonClass }}">
                <x-site-icon name="link" class="h-5 w-5" />
                <span data-label>{{ __('Copy link') }}</span>
            </button>
            <a href="mailto:?subject={{ rawurlencode($shareTitle) }}&body={{ rawurlencode($shareUrl) }}" class="{{ $shareButtonClass }}">
                <x-site-icon name="envelope" class="h-5 w-5" />
                {{ __('Email') }}
            </a>
            <a href="sms:?&body={{ rawurlencode($shareTitle.' '.$shareUrl) }}" class="{{ $shareButtonClass }}">
                <x-site-icon name="chat-bubble" class="h-5 w-5" />
                {{ __('Messages') }}
            </a>
            <a href="https://wa.me/?text={{ rawurlencode($shareTitle.' '.$shareUrl) }}" target="_blank" rel="noopener" class="{{ $shareButtonClass }}">
                <x-site-icon name="whatsapp" class="h-5 w-5" />
                WhatsApp
            </a>
            <a href="fb-messenger://share/?link={{ rawurlencode($shareUrl) }}" class="{{ $shareButtonClass }}">
                <x-site-icon name="messenger" class="h-5 w-5" />
                Messenger
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($shareUrl) }}" target="_blank" rel="noopener" class="{{ $shareButtonClass }}">
                <x-site-icon name="facebook" class="h-5 w-5" />
                Facebook
            </a>
            <a href="https://twitter.com/intent/tweet?text={{ rawurlencode($shareTitle) }}&url={{ rawurlencode($shareUrl) }}" target="_blank" rel="noopener" class="{{ $shareButtonClass }}">
                <x-site-icon name="x-twitter" class="h-5 w-5" />
                Twitter
            </a>
            <button type="button" data-share-embed
                    data-embed='<iframe src="{{ $shareUrl }}" width="800" height="600" style="border:0;border-radius:12px" loading="lazy"></iframe>'
                    class="{{ $shareButtonClass }}">
                <x-site-icon name="code-bracket" class="h-5 w-5" />
                <span data-label>{{ __('Embed') }}</span>
            </button>
            <button type="button" data-share-more data-url="{{ $shareUrl }}" data-title="{{ $shareTitle }}"
                    class="{{ $shareButtonClass }} hidden">
                <x-site-icon name="ellipsis" class="h-5 w-5" />
                {{ __('More options') }}
            </button>
        </div>
    </div>
</dialog>
