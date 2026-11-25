@php
    $input = 'mt-1 w-full rounded-lg border-stone-300 focus:border-rose-500 focus:ring-rose-500';
    $value = fn (int $position, string $field, $saved) => old("travellers.{$position}.{$field}", $field === 'birth_date' ? $saved?->birth_date?->toDateString() : $saved?->{$field});
@endphp
<x-site-layout :title="__('Register your guests')" :property="$property">
    <div class="mx-auto max-w-3xl px-4 py-10">
        <h1 class="text-2xl font-bold text-stone-900">{{ __('Register your guests') }}</h1>
        <p class="mt-2 text-stone-600">
            {{ __('The law of the country of :accommodation requires us to register every guest with the authorities. Please fill in the details of everyone who stays, babies included, before :date.', [
                'accommodation' => $property->localized('name'),
                'date' => $booking->arrival_date->translatedFormat('l j F Y'),
            ]) }}
        </p>
        <p class="mt-2 text-sm text-stone-500">{{ __('Your details are stored securely, only passed on as the law requires, and erased after your stay.') }}</p>

        @if (session('registration_saved'))
            <div class="mt-6 flex items-center gap-3 rounded-xl bg-green-50 p-4 text-green-800">
                <x-site-icon name="check-circle" class="h-6 w-6 shrink-0" />
                {{ __('Thank you, the details of your guests have been saved. You can change them here until your stay.') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-6 rounded-xl bg-red-50 p-4 text-sm text-red-700">{{ __('Please check the fields marked in red.') }}</div>
        @endif

        <form method="post" action="{{ $booking->signedLink('guest-registration.store') }}" class="mt-8 space-y-6">
            @csrf
            @foreach ($travellers as $index => $saved)
                @php $position = $index + 1; @endphp
                <fieldset class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-lg font-semibold text-stone-900">
                        {{ $position === 1 ? __('Main guest') : __('Guest :number', ['number' => $position]) }}
                    </legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('First name') }} *</span>
                            <input type="text" name="travellers[{{ $position }}][first_name]" required maxlength="100" value="{{ $value($position, 'first_name', $saved) }}" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Last name(s)') }} *</span>
                            <input type="text" name="travellers[{{ $position }}][last_name]" required maxlength="150" value="{{ $value($position, 'last_name', $saved) }}" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Sex') }} *</span>
                            <select name="travellers[{{ $position }}][sex]" required class="{{ $input }}">
                                <option value=""></option>
                                @foreach (\App\Models\GuestRegistration::SEXES as $key => $label)
                                    <option value="{{ $key }}" @selected($value($position, 'sex', $saved) === $key)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Date of birth') }} *</span>
                            <input type="date" name="travellers[{{ $position }}][birth_date]" required max="{{ today()->toDateString() }}" value="{{ $value($position, 'birth_date', $saved) }}" class="{{ $input }}">
                        </label>
                        @foreach (['nationality' => __('Nationality').' *', 'birth_country' => __('Country of birth')] as $field => $label)
                            <label class="block">
                                <span class="text-sm font-medium text-stone-700">{{ $label }}</span>
                                <select name="travellers[{{ $position }}][{{ $field }}]" @required($field === 'nationality') class="{{ $input }}">
                                    <option value=""></option>
                                    @foreach ($countries as $code => $name)
                                        <option value="{{ $code }}" @selected($value($position, $field, $saved) === $code)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    </div>

                    <p class="mt-5 text-sm font-medium text-stone-900">{{ __('Identity document') }}</p>
                    <p class="text-xs text-stone-500">{{ __('Required from the age of :age.', ['age' => \App\Models\GuestRegistration::DOCUMENT_FROM_AGE]) }}</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Type') }}</span>
                            <select name="travellers[{{ $position }}][document_type]" class="{{ $input }}">
                                <option value=""></option>
                                @foreach (\App\Models\GuestRegistration::DOCUMENT_TYPES as $key => $label)
                                    <option value="{{ $key }}" @selected($value($position, 'document_type', $saved) === $key)>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Document number') }}</span>
                            <input type="text" name="travellers[{{ $position }}][document_number]" maxlength="50" autocomplete="off" value="{{ $value($position, 'document_number', $saved) }}" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Issued by') }}</span>
                            <select name="travellers[{{ $position }}][document_country]" class="{{ $input }}">
                                <option value=""></option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}" @selected($value($position, 'document_country', $saved) === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <p class="mt-5 text-sm font-medium text-stone-900">{{ __('Place of residence') }}</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Country') }}@if ($position === 1) *@endif</span>
                            <select name="travellers[{{ $position }}][residence_country]" @required($position === 1) class="{{ $input }}">
                                <option value=""></option>
                                @foreach ($countries as $code => $name)
                                    <option value="{{ $code }}" @selected($value($position, 'residence_country', $saved) === $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('City') }}@if ($position === 1) *@endif</span>
                            <input type="text" name="travellers[{{ $position }}][residence_city]" maxlength="100" @required($position === 1) value="{{ $value($position, 'residence_city', $saved) }}" class="{{ $input }}">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-stone-700">{{ __('Address') }}</span>
                            <input type="text" name="travellers[{{ $position }}][residence_address]" maxlength="255" value="{{ $value($position, 'residence_address', $saved) }}" class="{{ $input }}">
                        </label>
                    </div>

                    @foreach (['first_name', 'last_name', 'sex', 'birth_date', 'nationality', 'birth_country', 'document_type', 'document_number', 'document_country', 'residence_country', 'residence_city', 'residence_address'] as $field)
                        @error("travellers.{$position}.{$field}")
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    @endforeach
                </fieldset>
            @endforeach

            <button type="submit" class="inline-flex items-center gap-2 rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white hover:from-rose-700 hover:to-pink-700">
                <x-site-icon name="users" class="h-5 w-5" />
                {{ __('Save the guest details') }}
            </button>
        </form>
    </div>
</x-site-layout>
