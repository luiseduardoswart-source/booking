@extends('install.layout', ['step' => 1])

@section('title', __('Database'))
@section('subtitle', __('Step 1 of 2 — connect the site to your database.'))

@section('content')
    {{-- System check: what the server must be able to do before we begin --}}
    <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">{{ __('System check') }}</h2>
    <ul class="mb-6 space-y-1.5 text-sm">
        @foreach ($checks as $check)
            <li class="flex items-start gap-2">
                @if ($check['ok'])
                    <span class="mt-0.5 text-emerald-600" aria-hidden="true">✓</span>
                    <span class="text-stone-600">{{ $check['label'] }}</span>
                @else
                    <span class="mt-0.5 text-red-600" aria-hidden="true">✕</span>
                    <span class="font-medium text-red-700">{{ $check['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ul>

    @unless ($ready)
        <p class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ __('Fix the points marked in red first. Enable missing PHP extensions at your hosting provider; set write permissions with chmod 775 on the folders listed.') }}
        </p>
    @endunless

    @if (session('db_error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <p class="mb-1 font-semibold">{{ __('Connection failed') }}</p>
            <pre class="whitespace-pre-wrap break-words font-mono text-xs">{{ session('db_error') }}</pre>
        </div>
    @endif

    <form method="POST" action="{{ route('install.database.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="app_url" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Site address') }}</label>
            <input type="url" name="app_url" id="app_url" required
                   value="{{ old('app_url', \App\Http\Controllers\InstallController::suggestedAddress()) }}"
                   class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
            @error('app_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="db_host" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Database server') }}</label>
                <input type="text" name="db_host" id="db_host" required
                       value="{{ old('db_host', $default['host']) }}"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
                @error('db_host')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="db_port" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Port') }}</label>
                <input type="number" name="db_port" id="db_port" required
                       value="{{ old('db_port', $default['port']) }}"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
                @error('db_port')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div>
            <label for="db_database" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Database name') }}</label>
            <input type="text" name="db_database" id="db_database" required
                   value="{{ old('db_database', $default['database']) }}"
                   class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
            @error('db_database')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="db_username" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Username') }}</label>
                <input type="text" name="db_username" id="db_username" required
                       value="{{ old('db_username', $default['username']) }}"
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
                @error('db_username')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="db_password" class="mb-1 block text-sm font-medium text-stone-700">{{ __('Password') }}</label>
                <input type="password" name="db_password" id="db_password"
                       @if ($hasPassword) placeholder="{{ __('Leave unchanged') }}" @endif
                       class="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-rose-500 focus:ring-2 focus:ring-rose-500/30 focus:outline-none">
                @error('db_password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @if ($hasPassword)
                    <p class="mt-1 text-xs text-stone-500">{{ __('Leave empty = the password from .env stays as it is.') }}</p>
                @endif
            </div>
        </div>

        <p class="text-xs text-stone-500">
            {{ __('You get these details from your hosting provider (often under "MySQL databases"). They are stored in the .env file on the server.') }}
        </p>

        <button type="submit"
                class="w-full rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700 disabled:cursor-not-allowed disabled:opacity-40"
                @disabled(! $ready)>
            {{ __('Connect and create tables') }}
        </button>
    </form>
@endsection
