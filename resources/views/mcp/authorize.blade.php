{{-- "Allow" screen of the AI connection (Passport::authorizationView in
     AppServiceProvider). Shown after logging in to the admin when an AI app
     connects; Passport passes $client, $user, $scopes, $request and
     $authToken. Only someone who may use the admin can allow; the MCP route
     checks that again on every request (AuthenticateMcpUser). --}}
@php
    $account = \App\Models\User::query()->find($user->getAuthIdentifier());
    $mayConnect = $account?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) ?? false;
    // The language of the admin, not of the website.
    app()->setLocale(\App\Http\Middleware\SetAdminLocale::localeFor(request()));
    $redirectUri = $request->query('redirect_uri', $client->redirect_uris[0] ?? null);
    $site = \App\Models\Setting::get('company', 'name') ?: config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Connect an AI app') }} · {{ $site }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-100 font-sans text-stone-900 antialiased">
<main class="flex min-h-screen items-center justify-center p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-stone-200 sm:p-8">
        <p class="text-sm text-stone-500">{{ $site }}</p>
        <h1 class="mt-1 text-2xl font-semibold">{{ __('Connect :app', ['app' => $client->name]) }}</h1>

        <p class="mt-4 rounded-lg bg-stone-50 p-3 text-sm">
            {{ __('Logged in as') }} <strong>{{ $account?->name }}</strong> ({{ $account?->email }})
        </p>

        @if ($mayConnect)
            <p class="mt-4 text-sm leading-relaxed text-stone-700">
                {{ __(':app may then read and change this website on your behalf, with exactly the rights of your account: accommodations, prices, the calendar, bookings and, for the owner, the layout. Payments, refunds, deleting, settings and users stay in the admin.', ['app' => $client->name]) }}
            </p>
            @if ($redirectUri)
                <p class="mt-3 break-all text-xs text-stone-500">{{ __('Afterwards you go back to :address', ['address' => parse_url($redirectUri, PHP_URL_HOST) ?: $redirectUri]) }}</p>
            @endif
            <p class="mt-3 text-xs text-stone-500">{{ __('You can end the connection at any time under Settings → AI connection.') }}</p>
        @else
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                {{ __('Your account cannot use the admin, so it cannot be connected to an AI app.') }}
            </p>
        @endif

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-lg border border-stone-300 px-4 py-2.5 text-sm font-medium transition hover:bg-stone-50">
                    <x-site-icon name="x-mark" class="h-4 w-4" /> {{ __('Cancel') }}
                </button>
            </form>
            @if ($mayConnect)
                <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                    @csrf
                    <input type="hidden" name="state" value="">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-lg bg-stone-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-stone-700">
                        <x-site-icon name="check-circle" class="h-4 w-4" /> {{ __('Allow') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</main>
</body>
</html>
