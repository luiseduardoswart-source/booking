@extends('install.layout', ['step' => 2])

@section('title', __('Administrator'))
@section('subtitle', __('Step 2 of 2 — create your own account.'))

@section('content')
    <p class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
        {{ __('The database is ready. One more step: the account you will use to log in to the admin.') }}
    </p>

    <form method="POST" action="{{ route('install.account.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="name" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Name') }}</label>
            <input type="text" name="name" id="name" required autofocus
                   value="{{ old('name') }}"
                   class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
            @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Email address') }}</label>
            <input type="email" name="email" id="email" required
                   value="{{ old('email') }}"
                   class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
            @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <x-password-fields />

        <div>
            <label for="site_language" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Default language of the website') }}</label>
            <select name="site_language" id="site_language"
                    class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
                @foreach (\App\Support\Languages::available() as $locale)
                    <option value="{{ $locale }}" @selected(old('site_language', 'en') === $locale)>{{ \App\Support\Languages::name($locale) }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-stone-500">
                {{ __('The language your website opens in for visitors without a clear preference. You can change this later under Settings → Website → Languages.') }}
            </p>
            @error('site_language')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit"
                class="w-full rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
            {{ __('Create account and sign in') }}
        </button>

        <p class="text-center text-xs text-stone-500">
            {{ __('After this the installer closes itself and you land in the admin.') }}
        </p>
    </form>
@endsection
