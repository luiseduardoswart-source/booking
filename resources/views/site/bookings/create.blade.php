<x-site-layout :title="__('Complete your booking')" :property="$property">
    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6">
        <h1 class="text-3xl font-bold text-stone-900">{{ __('Complete your booking') }}</h1>

        <div class="mt-8 grid gap-8 md:grid-cols-5">
            {{-- Guest details --}}
            <form action="{{ route('bookings.store', $property->slugFor()) }}" method="post" class="md:col-span-3 space-y-4">
                @csrf
                <input type="hidden" name="arrival" value="{{ $quote->arrival->toDateString() }}">
                <input type="hidden" name="departure" value="{{ $quote->departure->toDateString() }}">
                <input type="hidden" name="guests" value="{{ $quote->guests }}">
                @if (($breakdown['adults'] ?? null) !== null)
                    <input type="hidden" name="adults" value="{{ $breakdown['adults'] }}">
                    <input type="hidden" name="children" value="{{ $breakdown['children'] }}">
                    <input type="hidden" name="infants" value="{{ $breakdown['infants'] }}">
                    <input type="hidden" name="pets" value="{{ $breakdown['pets'] }}">
                @endif
                @if ($coupon)
                    <input type="hidden" name="coupon" value="{{ $coupon->code }}">
                @endif

                @if ($errors->any())
                    <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700">
                        <ul class="list-disc pl-4">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <h2 class="text-lg font-semibold text-stone-900">{{ __('Your details') }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-stone-700">{{ __('Full name') }} *</span>
                        <input type="text" name="name" required value="{{ old('name') }}"
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-stone-700">{{ __('Email address') }} *</span>
                        <input type="email" name="email" required value="{{ old('email') }}"
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-stone-700">{{ __('Phone') }}@if ($property->phoneRequired()) *@endif</span>
                        <input type="tel" name="phone" value="{{ old('phone') }}" @required($property->phoneRequired())
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                        @if ($property->phoneRequired())
                            <span class="mt-1 block text-xs text-stone-500">{{ __('The last four digits are the code of the door.') }}</span>
                        @endif
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-stone-700">{{ __('Address') }}</span>
                        <input type="text" name="address" value="{{ old('address') }}"
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-stone-700">{{ __('Postal code') }}</span>
                        <input type="text" name="postal_code" value="{{ old('postal_code') }}"
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-stone-700">{{ __('City') }}</span>
                        <input type="text" name="city" value="{{ old('city') }}"
                               class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-stone-700">{{ __('Remarks') }}</span>
                        <textarea name="notes" rows="3"
                                  class="mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500">{{ old('notes') }}</textarea>
                    </label>
                </div>

                {{-- Signing, only if the rental terms apply to this stay --}}
                @if ($property->rentalTermsApply($quote->nights))
                    <x-signature-field name-field="name" />
                @endif

                {{-- Agreement to the terms (required) --}}
                <label class="flex items-start gap-3 text-sm text-stone-700">
                    <input type="checkbox" name="terms" value="1" required
                           class="mt-0.5 rounded border-stone-300 text-rose-600 focus:ring-rose-500">
                    <span>
                        @if ($property->rentalTermsApply($quote->nights))
                            {{ __('I agree to the rental conditions, house rules and cancellation policy.') }}
                        @else
                            {{ __('I agree to the house rules and cancellation policy.') }}
                        @endif
                        <button type="button" onclick="terms_dialog.showModal()" class="underline hover:text-rose-600">
                            {{ __('Read the conditions') }}
                        </button>
                    </span>
                </label>

                <button type="submit"
                        class="w-full rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                    @if ($quote->downPaymentCents < $quote->totalCents)
                        {{ __('Confirm and pay down payment (:amount)', ['amount' => \App\Support\Money::format($quote->downPaymentCents, $quote->currency)]) }}
                    @else
                        {{ __('Confirm and pay (:amount)', ['amount' => \App\Support\Money::format($quote->totalCents, $quote->currency)]) }}
                    @endif
                </button>
                <p class="text-center text-xs text-stone-400">
                    {{ __('You will be redirected to our secure payment environment.') }}
                </p>
            </form>

            {{-- Summary --}}
            <div class="md:col-span-2">
                <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                    <h2 class="font-semibold text-stone-900">{{ $property->localized('name') }}</h2>
                    <p class="mt-1 text-sm text-stone-500">
                        {{ $quote->arrival->translatedFormat('D j M Y') }} → {{ $quote->departure->translatedFormat('D j M Y') }}<br>
                        {{ $quote->nights }} {{ $quote->nights === 1 ? __('night') : __('nights') }}
                        · {{ $quote->guests }} {{ $quote->guests === 1 ? __('guest') : __('guests') }}@if (($breakdown['infants'] ?? 0) > 0) · {{ $breakdown['infants'] }} {{ $breakdown['infants'] == 1 ? __('infant') : __("infants") }}@endif @if (($breakdown['pets'] ?? 0) > 0) · {{ $breakdown['pets'] }} {{ $breakdown['pets'] == 1 ? __('pet') : __('pets') }}@endif
                    </p>
                    <dl class="mt-4 space-y-1 border-t border-stone-200 pt-4 text-sm">
                        @foreach ($quote->lines as $line)
                            <div class="flex justify-between">
                                <dt class="text-stone-600">{{ $line->label }}</dt>
                                <dd>{{ \App\Support\Money::format($line->amountCents, $quote->currency) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between border-t border-stone-200 pt-2 font-semibold">
                            <dt>{{ __('Total') }}</dt>
                            <dd>{{ \App\Support\Money::format($quote->totalCents, $quote->currency) }}</dd>
                        </div>
                        @if ($quote->depositCents > 0)
                            <div class="flex justify-between text-stone-500">
                                <dt>{{ __('Deposit (separate)') }}</dt>
                                <dd>{{ \App\Support\Money::format($quote->depositCents, $quote->currency) }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if (\App\Support\Money::displayCurrencyFor($quote->currency) !== null)
                        <p class="mt-2 text-xs text-stone-400">
                            {{ __('Prices are converted at the current exchange rate; payment is made in :currency (:code).', [
                                'currency' => \App\Support\Currencies::name($quote->currency) ?? $quote->currency,
                                'code' => $quote->currency,
                            ]) }}
                        </p>
                    @endif

                    {{-- Discount code --}}
                    <div class="mt-4 border-t border-stone-200 pt-4">
                        @if ($coupon)
                            <p class="flex items-center justify-between text-sm">
                                <span class="font-medium text-green-700">✓ {{ __('Coupon :code applied', ['code' => strtoupper($coupon->code)]) }}</span>
                                <a href="{{ route('bookings.create', array_merge(['property' => $property->slugFor()], request()->except('coupon'))) }}"
                                   class="text-stone-500 underline hover:text-rose-600">{{ __('Remove') }}</a>
                            </p>
                        @else
                            <form action="{{ route('bookings.create', $property->slugFor()) }}" method="get" class="space-y-2">
                                @foreach (request()->except('coupon') as $name => $value)
                                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                @endforeach
                                <label for="coupon" class="text-sm font-medium text-stone-700">{{ __('Coupon code') }}</label>
                                <div class="flex gap-2">
                                    <input type="text" name="coupon" id="coupon" value="{{ request('coupon') }}"
                                           placeholder="{{ __('E.g. SUMMER10') }}"
                                           class="w-full rounded-lg border-stone-300 text-sm uppercase focus:border-rose-500 focus:ring-rose-500">
                                    <button type="submit"
                                            class="shrink-0 rounded-lg border border-stone-800 px-3 py-2 text-sm font-medium transition hover:bg-stone-100">
                                        {{ __('Apply') }}
                                    </button>
                                </div>
                                @if ($couponError)
                                    <p class="text-sm text-red-600">{{ $couponError }}</p>
                                @endif
                            </form>
                        @endif
                    </div>

                    @if ($property->cancellationPolicyLines() !== [])
                        <div class="mt-4 border-t border-stone-200 pt-4 text-xs text-stone-500">
                            <p class="font-semibold text-stone-700">
                                {{ __('Cancellation policy') }}@if ($property->effectiveCancellationPolicy() !== \App\Enums\CancellationPolicy::Custom) — {{ $property->effectiveCancellationPolicy()->getLabel() }}@endif
                            </p>
                            <ul class="mt-1 list-disc space-y-1 pl-4">
                                @foreach ($property->cancellationPolicyLines() as $rule)
                                    <li>{{ $rule }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Terms dialog --}}
        <dialog id="terms_dialog" class="m-auto w-full max-w-2xl rounded-2xl p-0 backdrop:bg-black/60">
            <div class="sticky top-0 flex items-center justify-between border-b border-stone-100 bg-white px-6 py-4">
                <strong>{{ __('Conditions') }}</strong>
                <button onclick="terms_dialog.close()" class="flex items-center gap-1.5 rounded-full px-3 py-1 hover:bg-stone-100">
                    <x-site-icon name="x-mark" class="h-4 w-4" /> {{ __('Close') }}
                </button>
            </div>
            <div class="whitespace-pre-line p-6 text-sm text-stone-700">{{ $property->bookingTermsText($quote->nights) ?: __('No additional conditions have been set.') }}</div>
        </dialog>
    </div>
</x-site-layout>
