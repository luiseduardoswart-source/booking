{{--
    Password + repeat, with a strength meter and a button that suggests a strong
    password. Taken over from devsite (user management), with two
    differences: the meter looks at length instead of composition, and the
    requirements come from the same rule the server enforces (Password::defaults
    in AppServiceProvider). So the meter and the server always say the same.
--}}
@php
    $rules = \Illuminate\Validation\Rules\Password::default()->appliedRules();
    $min = (int) $rules['min'];
    $max = $rules['max'] ? (int) $rules['max'] : null;
    $field = 'w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none';
    $button = 'shrink-0 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm font-medium text-stone-700 transition hover:bg-stone-50';

    // Calculate beforehand: @json() splits its argument on commas and trips
    // over nested calls like __('...').
    $labels = [__('Weak'), __('Fair'), __('Good'), __('Strong')];
    $colors = ['bg-red-500', 'bg-amber-500', 'bg-emerald-500', 'bg-emerald-600'];
@endphp

<div data-password
     data-min="{{ $min }}"
     data-max="{{ $max ?? '' }}"
     data-labels="{{ json_encode($labels) }}"
     data-to-short="{{ __('Too short: :count more characters') }}"
     data-colors="{{ json_encode($colors) }}"
     data-copied="{{ __('Copied') }}"
     data-copy="{{ __('Copy') }}"
     class="space-y-4">

    <div>
        <label for="password" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Password') }}</label>
        <div class="flex gap-2">
            <input type="password" name="password" id="password" required
                   minlength="{{ $min }}" @if ($max) maxlength="{{ $max }}" @endif
                   autocomplete="new-password" data-input
                   class="{{ $field }}">
            <button type="button" data-show class="{{ $button }}"
                    aria-label="{{ __('Show password') }}" title="{{ __('Show password') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
            </button>
            <button type="button" data-proposal class="{{ $button }}">
                {{ __('Suggest') }}
            </button>
        </div>

        <div class="mt-2 flex items-center gap-3">
            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-stone-200">
                <div data-bar class="h-full w-0 rounded-full transition-all"></div>
            </div>
            <span data-label aria-live="polite" class="min-w-32 text-right text-xs text-stone-500"></span>
        </div>

        <div data-generated class="mt-2 hidden items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
            <span>{{ __('Save this password in your password manager before you continue.') }}</span>
            <button type="button" data-copy class="shrink-0 font-semibold underline">{{ __('Copy') }}</button>
        </div>

        <p class="mt-1 text-xs text-stone-500">
            {{ __('At least :min characters. A phrase of a few separate words is fine too. When saving, we check whether this password has ever been leaked.', ['min' => $min]) }}
        </p>
        @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="password_confirmation" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Repeat password') }}</label>
        <input type="password" name="password_confirmation" id="password_confirmation" required
               autocomplete="new-password" data-repeat
               class="{{ $field }}">
    </div>
</div>

@once
    <script src="{{ asset('js/password.js') }}" defer></script>
@endonce
