@props(['title' => null, 'metaDescription' => null, 'property' => null, 'translated' => null])

@php
    $siteName = \App\Models\Setting::get('company', 'name', config('app.name'));
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{ \App\Support\Favicon::tags() }}
    <title>{{ $title ? "$title — $siteName" : $siteName }}</title>
    @if ($metaDescription)
        <meta name="description" content="{{ $metaDescription }}">
    @endif
    <meta property="og:title" content="{{ $title ?? $siteName }}">
    @if ($metaDescription)
        <meta property="og:description" content="{{ $metaDescription }}">
    @endif
    @if ($translated)
        <link rel="canonical" href="{{ url($translated->slugFor()) }}">
        @foreach ($translated->languageAddresses() as $language => $address)
            <link rel="alternate" hreflang="{{ $language }}" href="{{ $address }}">
        @endforeach
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @php
        // Style template: only validated color values make it into the stylesheet.
        $color = fn (string $key) => \App\Support\SiteTemplates::isColor($w = \App\Models\Setting::get('frontend', $key)) ? $w : null;
        $accentColor = $color('accent_color');
        $labelColor = $color('label_color');
        $backgroundColor = $color('background_color');
        $headerDark = \App\Models\Setting::get('frontend', 'header_style') === 'dark';
        $fontStyle = \App\Models\Setting::get('frontend', 'font_style');
        $buttonTight = \App\Models\Setting::get('frontend', 'button_style') === 'tight';
    @endphp
    @if ($accentColor || $labelColor || $backgroundColor || $headerDark || in_array($fontStyle, ['serif', 'system'], true) || $buttonTight)
        <style>
            :root {
                @if ($labelColor) --color-label: {{ $labelColor }}; @endif
                @if ($accentColor) --color-accent: {{ $accentColor }}; @endif
            }
            @if ($accentColor)
            .button-accent { background-color: var(--color-accent) !important; background-image: none !important; }
            .button-accent:hover { filter: brightness(0.92); }
            .text-rose-600 { color: var(--color-accent) !important; }
            .hover\:text-rose-600:hover { color: var(--color-accent) !important; }
            .focus\:border-rose-500:focus { border-color: var(--color-accent) !important; }
            .focus\:ring-rose-500:focus { --tw-ring-color: var(--color-accent); }
            .bg-rose-50 { background-color: color-mix(in srgb, var(--color-accent) 8%, white) !important; }
            .from-rose-50 { --tw-gradient-from: color-mix(in srgb, var(--color-accent) 6%, white) !important; }
            @endif
            @if ($backgroundColor)
            body { background-color: {{ $backgroundColor }} !important; }
            @endif
            @if ($headerDark)
            .site-header { background-color: rgba(22, 20, 19, 0.95) !important; border-color: rgba(255, 255, 255, 0.12) !important; color: #f5f5f4; }
            .site-header summary { border-color: rgba(255, 255, 255, 0.35); }
            @endif
            @if ($fontStyle === 'serif')
            h1, h2, h3, h4 { font-family: 'Lora', Georgia, 'Times New Roman', serif; }
            @elseif ($fontStyle === 'system')
            body { font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }
            @endif
            @if ($buttonTight)
            .button-accent { border-radius: 0.5rem !important; }
            @endif
        </style>
    @endif
    @php $layoutActive = \App\Support\SiteLayouts::active(); @endphp
    @if ($layoutActive !== \App\Support\SiteLayouts::DEFAULT && ($templateCss = \App\Support\SiteLayouts::stylesheetUrl($layoutActive)))
        {{-- Own stylesheet of the active layout template (shipped in the zip) --}}
        <link rel="stylesheet" href="{{ $templateCss }}">
    @endif
    @if (($visualCss = \App\Support\VisualStyle::css()) !== '')
        {{-- Rules from the visual style editor (admin → Style editor); wins over everything above --}}
        <style data-visual-style>{!! $visualCss !!}</style>
    @endif
    {!! \App\Models\Setting::get('frontend', 'head_code') !!}
    {{ $head ?? '' }}
</head>
<body class="min-h-screen overflow-x-clip bg-white text-stone-800 antialiased flex flex-col">
    <header class="site-header sticky top-0 z-40 border-b border-stone-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 sm:px-6 sm:py-4">
            <a href="{{ route('home') }}" class="flex items-center gap-2 text-xl font-bold tracking-tight text-rose-600">
                @if ($logo = \App\Models\Setting::get('branding', 'logo'))
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logo) }}"
                         alt="{{ $siteName }}" class="h-9 w-auto">
                @else
                    {{ $siteName }}
                @endif
            </a>
            {{-- Menu pages (manageable via admin → Pages) --}}
            @php $menuPages = \App\Models\Page::menuItems(); @endphp
            {{-- Also render in the style editor without pages: then the
                 + button (new menu item = new page) can still appear. --}}
            @if ($menuPages->isNotEmpty() || request()->boolean('style-editor'))
                <nav class="hidden items-center gap-1 md:flex" data-sw-nav="menu">
                    @foreach ($menuPages as $menuPage)
                        <a href="{{ url('/'.$menuPage->slugFor()) }}"
                           class="rounded-full px-3 py-1.5 text-sm font-medium transition hover:bg-stone-100 {{ request()->path() === $menuPage->slugFor() ? 'font-bold text-rose-600' : 'text-stone-700' }}">
                            {{ $menuPage->localized('title') }}
                        </a>
                    @endforeach
                </nav>
            @endif

            <div class="flex items-center gap-2">
            {{-- Currency picker (display only; payment stays in euros) --}}
            @php
                $currencyPickerOn = \App\Models\Setting::get('site', 'currency_picker', '0') === '1';
                $availableCurrencies = $currencyPickerOn ? \App\Support\Money::availableCurrencies() : [];
                $activeCurrency = strtoupper((string) (session('currency') ?: \App\Models\Setting::get('site', 'currency_default', 'EUR')));
                $activeCurrency = in_array($activeCurrency, $availableCurrencies, true) ? $activeCurrency : 'EUR';
            @endphp
            @if ($currencyPickerOn && count($availableCurrencies) > 1)
                <details class="relative">
                    <summary class="flex cursor-pointer select-none items-center gap-1.5 rounded-full border border-stone-300 px-3 py-1.5 text-sm font-medium transition hover:shadow [&::-webkit-details-marker]:hidden">
                        @php $symbolFor = fn (string $code) => (($s = \App\Support\Currencies::symbol($code)) !== null && $s !== $code) ? $s : null; @endphp
                        {{ $activeCurrency }} {{ $symbolFor($activeCurrency) }}
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                    </summary>
                    {{-- Height follows the screen: never past the bottom edge, always scrollable --}}
                    <div class="absolute right-0 z-50 mt-2 flex max-h-[min(20rem,60vh)] w-44 flex-col overflow-y-auto overscroll-contain rounded-xl border border-stone-200 bg-white p-1 shadow-xl">
                        @foreach ($availableCurrencies as $currencyCode)
                            <a href="{{ route('currency.switch', $currencyCode) }}"
                               class="flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm transition hover:bg-stone-100 {{ $activeCurrency === $currencyCode ? 'font-bold text-rose-600' : 'text-stone-700' }}">
                                <span>{{ $currencyCode }} {{ $symbolFor($currencyCode) }}</span>
                                @if ($activeCurrency === $currencyCode)<span>✓</span>@endif
                            </a>
                        @endforeach
                    </div>
                </details>
            @endif

            {{-- Language picker as a dropdown --}}
            <details class="relative">
                <summary class="flex cursor-pointer select-none items-center gap-1.5 rounded-full border border-stone-300 px-3 py-1.5 text-sm font-medium uppercase transition hover:shadow [&::-webkit-details-marker]:hidden">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802"/></svg>
                    {{ app()->getLocale() }}
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                </summary>
                <div class="absolute right-0 z-50 mt-2 flex w-40 flex-col rounded-xl border border-stone-200 bg-white p-1 shadow-xl">
                    @foreach (\App\Support\Languages::active() as $locale)
                        <a href="{{ route('locale.switch', $locale) }}"
                           class="rounded-lg px-3 py-2 text-sm transition hover:bg-stone-100 {{ app()->getLocale() === $locale ? 'font-bold text-rose-600' : 'text-stone-700' }}">
                            {{-- Name of the language in that language itself (works for every new language) --}}
                            {{ extension_loaded('intl') ? \Illuminate\Support\Str::ucfirst(\Locale::getDisplayLanguage($locale, $locale)) : strtoupper($locale) }}
                        </a>
                    @endforeach
                </div>
            </details>
            </div>
        </div>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-stone-200 bg-stone-50">
        <div class="mx-auto max-w-7xl px-4 py-8 text-sm text-stone-500 sm:px-6">
            <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                <p>&copy; {{ now()->year }} {{ $siteName }}</p>
                {{-- On an accommodation's page its own links, otherwise the general ones. --}}
                @include('site.partials.social-links', ['links' => ($property ?? \App\Models\Property::master())->socialLinks()])
                <p class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1">
                    @foreach (\App\Models\Page::menuItems('footer') as $footerPage)
                        <a href="{{ url('/'.$footerPage->slugFor()) }}" class="hover:text-rose-600">{{ $footerPage->localized('title') }}</a>
                    @endforeach
                    <a href="{{ route('contact') }}" class="hover:text-rose-600">{{ __('Contact') }}</a>
                </p>
            </div>
        </div>
    </footer>

    @include('site.partials.cookie-notification')
    @include('site.partials.chat')

    {!! \App\Models\Setting::get('frontend', 'body_code') !!}
</body>
</html>
