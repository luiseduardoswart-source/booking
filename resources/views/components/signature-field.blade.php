{{--
    Drawing box for the digital signature under the rental terms. The script
    (public/js/signature.js) puts the drawing as a PNG in the hidden field;
    App\Support\Signature validates and stores it.

    $nameField: the field in the same form with the guest's name. That
    name belongs to the signature, so the guest does not have to enter it
    twice.
--}}
@props(['nameField' => 'name'])

<div data-signature
     data-name-field="{{ $nameField }}"
     data-notification="{{ __('Sign the conditions below.') }}"
     data-too-small="{{ __('Your signature is too small. Please sign again using more of the box.') }}"
     {{-- The same minimum size as the server checks (App\Support\Signature). --}}
     data-min-width="{{ \App\Support\Signature::MIN_WIDTH_SHARE }}"
     data-min-height="{{ \App\Support\Signature::MIN_HEIGHT_SHARE }}"
     class="rounded-xl border border-stone-200 bg-stone-50 p-4">
    <div class="flex items-center justify-between gap-3">
        {{-- The conditions: house rules, cancellation policy and, when they apply, the rental terms. --}}
        <span class="text-sm font-medium text-stone-700">{{ __('Sign the conditions') }} *</span>
        <button type="button" data-clear class="text-sm text-stone-600 underline hover:text-rose-600">{{ __('Clear') }}</button>
    </div>

    <canvas data-box
            class="mt-2 h-40 w-full cursor-crosshair touch-none rounded-lg border border-dashed border-stone-300 bg-white"
            aria-label="{{ __('Signature box') }}"></canvas>

    <p class="mt-2 text-xs text-stone-500">
        {{ __('Sign with your mouse or finger. Your signature is stored with the terms in your booking.') }}
    </p>
    <p data-signer-rule class="mt-1 hidden text-xs text-stone-600">
        {{ __('Signed by') }}: <strong data-signer></strong>
    </p>

    {{-- A refused signature is not put back: the guest draws a new one. --}}
    <input type="hidden" name="signature" value="{{ $errors->has('signature') ? '' : old('signature') }}" data-output>
    <p data-error class="mt-1 text-sm text-red-600 {{ $errors->has('signature') ? '' : 'hidden' }}">{{ $errors->first('signature') }}</p>
</div>

@once
    <script src="{{ asset('js/signature.js') }}" defer></script>
@endonce
