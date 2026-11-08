{{-- Standalone layout for the install wizard: deliberately does not touch the database
     (no settings, no pages), because it does not exist at that
     point yet. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — @yield('heading', __('Installation'))</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-stone-100 text-stone-800 antialiased">
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-stone-900 sm:text-3xl">@yield('heading', __('Installation'))</h1>
            <p class="mt-2 text-sm text-stone-500">@yield('subtitle')</p>

            {{-- Steps: 1 database, 2 administrator. Other pages using this
                 layout (such as the invitation to the admin) pass no step. --}}
            @isset($step)
            <div class="mt-6 flex items-center justify-center gap-3 text-xs font-medium">
                @foreach ([1 => __('Database'), 2 => __('Administrator')] as $number => $name)
                    @php $active = $number === ($step ?? 1); @endphp
                    <span class="flex items-center gap-2 {{ $active ? 'text-rose-600' : 'text-stone-400' }}">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border {{ $active ? 'border-rose-600 bg-rose-600 text-white' : 'border-stone-300' }}">
                            {{ $number }}
                        </span>
                        {{ $name }}
                    </span>
                    @if (! $loop->last)
                        <span class="h-px w-8 bg-stone-300"></span>
                    @endif
                @endforeach
            </div>
            @endisset
        </div>

        <div class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm sm:p-8">
            @yield('content')
        </div>
    </div>
</body>
</html>
