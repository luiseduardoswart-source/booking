<x-site-layout
    :property="$property"
    :translated="$property"
    :title="$property->localized('meta_title') ?? $property->localized('name')"
    :metaDescription="$property->localized('meta_description') ?? $property->localized('short_description')">

    <x-slot:head>
        <script type="application/ld+json">
        {
            "@@context": "https://schema.org",
            "@type": "LodgingBusiness",
            "name": @json($property->localized('name')),
            "description": @json($property->localized('short_description')),
            {{-- Approximate location: street and postal code stay out of what search engines read too. --}}
            "address": {
                "@type": "PostalAddress",
                "streetAddress": @json($property->showsExactLocation() ? $property->address : null),
                "postalCode": @json($property->showsExactLocation() ? $property->postal_code : null),
                "addressLocality": @json($property->city),
                "addressCountry": @json($property->country)
            }@if ($property->approved_reviews_count > 0),
            "aggregateRating": {
                "@type": "AggregateRating",
                "ratingValue": @json(round($property->approved_reviews_avg_rating, 1)),
                "reviewCount": @json($property->approved_reviews_count)
            }
            @endif
        }
        </script>
    </x-slot:head>

    @php $photos = $property->gallery(); @endphp

    {{-- Photo presentation: can be overridden per layout template --}}
    @include('site.properties.partials.hero')

    <div class="mx-auto max-w-7xl px-4 pb-8 sm:px-6">

        <div class="mt-8 grid gap-12 lg:grid-cols-3">
            {{-- Left column: information --}}
            <div class="lg:col-span-2">
                <h2 class="text-xl font-semibold text-stone-900">
                    {{ $property->typeName() }}@if ($property->city) {{ __('in') }} {{ $property->city }}@endif
                </h2>
                <p class="mt-1 text-stone-600">
                    {{ $property->max_guests }} {{ __('guests') }}
                    @if ($property->bedrooms) · {{ $property->bedrooms }} {{ __('bedrooms') }}@endif
                    @if ($property->beds) · {{ $property->beds }} {{ __('beds') }}@endif
                    @if ($property->sofa_beds) · {{ trans_choice(':count sofa bed|:count sofa beds', $property->sofa_beds) }}@endif
                    @if ($property->bathrooms) · {{ $property->bathrooms }} {{ __('bathrooms') }}@endif
                </p>

                {{-- Host (like Airbnb) --}}
                @foreach ($property->hosts as $host)
                    <div class="mt-6 flex items-center gap-4">
                        @if ($avatar = $host->getFirstMediaUrl('avatar', 'thumb'))
                            <img src="{{ $avatar }}" alt="{{ $host->name }}"
                                 class="h-12 w-12 rounded-full object-cover">
                        @else
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-stone-900 text-lg font-semibold text-white">
                                {{ mb_strtoupper(mb_substr($host->name, 0, 1)) }}
                            </span>
                        @endif
                        <div>
                            <p class="font-medium text-stone-900">{{ __('Hosted by :name', ['name' => $host->name]) }}</p>
                            @if ($bio = $host->localizedBio())
                                <p class="text-sm text-stone-500">{{ $bio }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- Highlights: own blocks from the admin, otherwise automatic --}}
                <div class="mt-6 space-y-4 border-y border-stone-200 py-6">
                    @if (! empty($property->highlights))
                        @foreach ($property->localizedHighlights() as $highlight)
                            <div class="flex items-start gap-4">
                                <x-site-icon :name="$highlight['icon'] ?? 'check-circle'" class="h-7 w-7 shrink-0 text-stone-700" />
                                <div>
                                    <p class="font-medium text-stone-900">{{ $highlight['title'] ?? '' }}</p>
                                    @if (! empty($highlight['text']))
                                        <p class="text-sm text-stone-500">{{ $highlight['text'] }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    @else
                    @php
                        $welcome = $property->checkInKind() === \App\Models\Property::CHECK_IN_WELCOME;
                        $welcomeHost = $welcome ? $property->checkInHost() : null;
                    @endphp
                    @if ($property->effective('check_in_from') || $welcome)
                        <div class="flex items-start gap-4">
                            @if ($welcomeHost && ($avatar = $welcomeHost->getFirstMediaUrl('avatar', 'thumb')))
                                <img src="{{ $avatar }}" alt="{{ $welcomeHost->name }}" class="h-7 w-7 shrink-0 rounded-full object-cover">
                            @else
                                <x-site-icon name="clock" class="h-7 w-7 shrink-0 text-stone-700" />
                            @endif
                            <div>
                                <p class="font-medium text-stone-900">
                                    @if ($welcomeHost)
                                        {{ __(':name welcomes you', ['name' => $welcomeHost->name]) }}
                                    @elseif ($welcome)
                                        {{ __('You are welcomed in person') }}
                                    @else
                                        {{ __('Self check-in') }}
                                    @endif
                                </p>
                                @if ($property->effective('check_in_from'))
                                    <p class="text-sm text-stone-500">
                                        {{ __('Check-in from :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_in_from'))->format('H:i')]) }}@if ($property->effective('check_out_until')), {{ __('check-out :time', ['time' => \Illuminate\Support\Carbon::parse($property->effective('check_out_until'))->format('H:i')]) }}@endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endif
                    @if ($property->publicCoordinates())
                        <div class="flex items-start gap-4">
                            <x-site-icon name="map-pin" class="h-7 w-7 shrink-0 text-stone-700" />
                            <div>
                                <p class="font-medium text-stone-900">{{ __('Great location') }}</p>
                                <a href="#where-you-stays" class="text-sm text-stone-500 underline hover:text-rose-600">
                                    {{ __('View on the map') }} ↓
                                </a>
                            </div>
                        </div>
                    @endif
                    <div class="flex items-start gap-4">
                        <x-site-icon name="credit-card" class="h-7 w-7 shrink-0 text-stone-700" />
                        <div>
                            <p class="font-medium text-stone-900">{{ __('Secure online payment') }}</p>
                            <p class="text-sm text-stone-500">{{ __('You pay securely online, in the protected environment of our payment provider.') }}</p>
                        </div>
                    </div>
                    @endif
                </div>

                {{-- Description --}}
                @if ($property->localized('description'))
                    <div class="prose prose-stone mt-6 max-w-none whitespace-pre-line">{!! \App\Support\RichText::render($property->localized('description')) !!}</div>
                @endif

                {{-- Amenities --}}
                @if ($property->amenities->isNotEmpty())
                    <div class="mt-8 border-t border-stone-200 pt-8">
                        <h2 class="text-xl font-semibold text-stone-900">{{ __('What this place offers') }}</h2>
                        <ul class="mt-4 grid gap-3 text-stone-700 sm:grid-cols-2">
                            @foreach ($property->amenities->take(\App\Models\Amenity::ON_THE_PAGE) as $amenity)
                                <li class="flex items-center gap-3">
                                    <x-site-icon :name="$amenity->iconName()" class="h-6 w-6 shrink-0 text-stone-700" />
                                    {{ $amenity->localizedName() }}
                                </li>
                            @endforeach
                        </ul>
                        @if ($property->amenities->count() > \App\Models\Amenity::ON_THE_PAGE)
                            <button type="button" onclick="amenities_dialog.showModal()"
                                    class="mt-6 rounded-lg border border-stone-900 px-5 py-3 font-semibold text-stone-900 transition hover:bg-stone-50">
                                {{ __('Show all :count amenities', ['count' => $property->amenities->count()]) }}
                            </button>
                            @include('site.properties.partials.amenities-modal')
                        @endif
                    </div>
                @endif

                {{-- House rules --}}
                @if ($property->effectiveLocalized('house_rules'))
                    <div class="mt-8 border-t border-stone-200 pt-8">
                        <h2 class="text-xl font-semibold text-stone-900">{{ __('House rules') }}</h2>
                        <p class="mt-3 whitespace-pre-line text-stone-700">{{ $property->effectiveLocalized('house_rules') }}</p>
                    </div>
                @endif

                {{-- Cancellation policy --}}
                @if ($property->cancellationPolicyLines() !== [])
                    <div class="mt-8 border-t border-stone-200 pt-8">
                        {{-- Only the rules: the name of the policy (Strict...) is for the admin. --}}
                        <h2 class="text-xl font-semibold text-stone-900">{{ __('Cancellation policy') }}</h2>
                        <ul class="mt-3 space-y-2 text-stone-700">
                            @foreach ($property->cancellationPolicyLines() as $rule)
                                <li class="flex items-start gap-3">
                                    <x-site-icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-stone-400" />
                                    {{ $rule }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Social media of this accommodation: after the rules, at the end --}}
                @if ($socialLinks = $property->socialLinks())
                    <div class="mt-8 border-t border-stone-200 pt-8">
                        <h2 class="text-xl font-semibold text-stone-900">{{ __('Follow us') }}</h2>
                        <div class="mt-4">
                            @include('site.partials.social-links', ['links' => $socialLinks])
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right column: sticky booking card --}}
            <div>
                {{-- z-10: sticky makes its own layer; without a z-index the map further down
                     (Leaflet, z-0) lies over the calendar that opens downwards on mobile. --}}
                <div id="booking_card" class="sticky top-24 z-10 scroll-mt-24 rounded-2xl border border-stone-200 bg-white p-6 shadow-xl">
                    <p class="text-xl">
                        <span class="font-semibold text-stone-900">{{ \App\Support\Money::format($property->basePrice(), $property->priceCurrency()) }}</span>
                        <span class="text-base text-stone-500">{{ __('per night') }}</span>
                    </p>

                    <form action="{{ route('bookings.create', $property->slugFor()) }}" method="get" class="mt-4" id="booking_form">
                        <input type="hidden" name="arrival" value="{{ request('arrival') }}" required>
                        <input type="hidden" name="departure" value="{{ request('departure') }}" required>

                        {{-- Airbnb-style combined date/guests box --}}
                        <div class="relative">
                            <div class="overflow-hidden rounded-xl border border-stone-400">
                                <div class="grid grid-cols-2 divide-x divide-stone-400 border-b border-stone-400">
                                    <button type="button" data-calendar-field="arrival" class="block p-3 text-left transition hover:bg-stone-50">
                                        <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Check-in') }}</span>
                                        <span data-calendar-text="arrival" class="text-sm text-stone-400">{{ __('Select date') }}</span>
                                    </button>
                                    <button type="button" data-calendar-field="departure" class="block p-3 text-left transition hover:bg-stone-50">
                                        <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Check-out') }}</span>
                                        <span data-calendar-text="departure" class="text-sm text-stone-400">{{ __('Select date') }}</span>
                                    </button>
                                </div>
                                <button type="button" data-guests-field class="block w-full p-3 text-left transition hover:bg-stone-50">
                                    <span class="block text-[10px] font-bold uppercase tracking-wide text-stone-800">{{ __('Guests') }}</span>
                                    <span data-guests-text class="text-sm text-stone-600"></span>
                                </button>
                            </div>

                            <x-guest-popover
                                :max="$property->max_guests"
                                :petsAllowed="$property->petsAllowed()"
                                :maxBabies="$property->maxBabies()"
                                :maxPets="$property->maxPets()"
                                class="left-0 right-0 top-full" />

                            <x-calendar-popover
                                :unavailableUrl="url('/api/v1/properties/'.$property->id.'/unavailable-dates')"
                                class="left-1/2 top-full -translate-x-1/2 lg:left-auto lg:right-0 lg:translate-x-0" />
                        </div>

                        <button type="submit" id="book_button"
                                class="mt-4 w-full rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-4 py-3.5 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ __('Reserve') }}
                        </button>
                        <p class="mt-2 text-center text-sm text-stone-500">{{ __('You won\'t be charged yet') }}</p>

                        <div id="price_error" class="mt-3 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700"></div>
                        <div id="price_overview" class="mt-4 hidden space-y-2 text-sm"></div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Reviews --}}
        @if ($property->approved_reviews_count > 0)
            <div id="reviews" class="mt-12 border-t border-stone-200 pt-8">
                <h2 class="flex items-center gap-2 text-xl font-semibold text-stone-900">
                    <span class="text-2xl">★</span>
                    {{ number_format($property->approved_reviews_avg_rating, 1, ',', '') }}
                    · {{ trans_choice('{1} 1 review|[2,*] :count reviews', $property->approved_reviews_count) }}
                </h2>
                <div class="mt-6 grid gap-8 sm:grid-cols-2">
                    @foreach ($property->approvedReviews as $review)
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-stone-900 font-semibold text-white">
                                    {{ mb_strtoupper(mb_substr($review->guest_name, 0, 1)) }}
                                </span>
                                <div>
                                    <p class="font-medium text-stone-900">{{ $review->guest_name }}</p>
                                    <p class="text-xs text-stone-500">{{ $review->created_at->translatedFormat('F Y') }} · ✓ {{ __('verified stay') }}</p>
                                </div>
                            </div>
                            <p class="mt-2 text-amber-400" aria-label="{{ $review->rating }}/5">{{ str_repeat('★', $review->rating) }}<span class="text-stone-300">{{ str_repeat('★', 5 - $review->rating) }}</span></p>
                            @if ($review->comment)
                                <p class="mt-1 text-stone-700">{{ $review->comment }}</p>
                            @endif

                            @if ($review->host_reply)
                                <div class="ml-6 mt-3 rounded-xl bg-stone-50 p-4">
                                    <p class="text-sm font-medium text-stone-900">
                                        {{ __('Response from :name', ['name' => $property->hosts->pluck('name')->join(' & ') ?: \App\Models\Setting::get('company', 'name', config('app.name'))]) }}
                                        @if ($review->host_replied_at)
                                            <span class="font-normal text-stone-400">· {{ $review->host_replied_at->translatedFormat('F Y') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-sm text-stone-600">{{ $review->host_reply }}</p>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Map: where you will stay. Approximately: a circle whose centre is not the
             real spot (see Property::publicCoordinates). Exactly: a pin on the address. --}}
        @if ($spot = $property->publicCoordinates())
            <div id="where-you-stays" class="mt-12 border-t border-stone-200 pt-8">
                <h2 class="text-xl font-semibold text-stone-900">{{ __('Where you\'ll be staying') }}</h2>
                <p class="mt-1 text-stone-600">
                    @if ($property->showsExactLocation() && $property->address){{ $property->address }}, @endif
                    @if ($property->city){{ $property->city }}, {{ \App\Support\Countries::name($property->country) }}@endif
                </p>
                <div id="map"
                     data-lat="{{ $spot['lat'] }}"
                     data-lng="{{ $spot['lng'] }}"
                     data-exact="{{ $property->showsExactLocation() ? '1' : '0' }}"
                     data-radius="{{ \App\Models\Property::LOCATION_RADIUS }}"
                     class="z-0 mt-4 h-96 w-full overflow-hidden rounded-2xl"></div>
                @unless ($property->showsExactLocation())
                    <p class="mt-2 text-xs text-stone-400">{{ __('You will receive the exact location after booking.') }}</p>
                @endunless
            </div>
        @endif
    </div>

    {{-- Mobile: the booking card is far down the page. A bar at the bottom takes
         the guest there; it hides while the card itself is in view. --}}
    <div id="book_bar" class="fixed inset-x-0 bottom-0 z-30 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur transition-transform duration-200 lg:hidden">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4">
            <p>
                <span class="font-semibold text-stone-900">{{ \App\Support\Money::format($property->basePrice(), $property->priceCurrency()) }}</span>
                <span class="text-sm text-stone-500">{{ __('per night') }}</span>
            </p>
            <a href="#booking_card" data-book-bar-link
               class="rounded-xl button-accent bg-gradient-to-r from-rose-600 to-pink-600 px-6 py-3 font-semibold text-white transition hover:from-rose-700 hover:to-pink-700">
                {{ __('Reserve') }}
            </a>
        </div>
    </div>
    <style>
        /* Room for the bar under the footer, and the chat button above the bar instead
           of on top of the Reserve button (body: wins over the chat's own later styles). */
        @media (max-width: 1023.98px) {
            body { padding-bottom: 5rem; }
            body .sc-button { bottom: 5.5rem; }
            body .sc-panel { bottom: 10rem; max-height: calc(100vh - 12rem); }
        }
    </style>
    <script>
        (function () {
            const bar = document.getElementById('book_bar');
            const card = document.getElementById('booking_card');

            if (!bar || !card) return;

            bar.querySelector('[data-book-bar-link]').addEventListener('click', function (event) {
                event.preventDefault();
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            new IntersectionObserver(([entry]) => {
                bar.classList.toggle('translate-y-full', entry.isIntersecting);
            }).observe(card);
        })();
    </script>

    <script>
        // Share modal: copy, embed code and native share.
        (function () {
            const copied = @json(__('Copied!'));

            function copy(button, text) {
                navigator.clipboard.writeText(text).then(() => {
                    const label = button.querySelector('[data-label]');
                    const original = label.textContent;
                    label.textContent = copied;
                    setTimeout(() => { label.textContent = original; }, 2000);
                });
            }

            document.querySelector('[data-share-copy]')?.addEventListener('click', function () {
                copy(this, this.dataset.url);
            });

            document.querySelector('[data-share-embed]')?.addEventListener('click', function () {
                copy(this, this.dataset.embed);
            });

            const more = document.querySelector('[data-share-more]');
            if (more && navigator.share) {
                more.classList.remove('hidden');
                more.addEventListener('click', function () {
                    navigator.share({ title: this.dataset.title, url: this.dataset.url }).catch(() => {});
                });
            }
        })();

        (function () {
            const form = document.getElementById('booking_form');
            const overview = document.getElementById('price_overview');
            const error = document.getElementById('price_error');
            const button = document.getElementById('book_button');
            // Amounts come in the currency of the accommodation; if the visitor has
            // chosen another one, they are converted with the same exchange_rate as the server.
            @php
                $priceCurrency = $property->priceCurrency();
                $displayCurrency = \App\Support\Money::displayCurrencyFor($priceCurrency);
            @endphp
            const priceCurrency = @json($priceCurrency);
            const view = @json($displayCurrency === null ? null : ['code' => $displayCurrency, 'exchange_rate' => \App\Support\Money::exchangeRate($priceCurrency, $displayCurrency)]);
            // Euros as everywhere on the site ("€ 1.234,50"), other currencies via Intl.
            const money = (amount, code) => code === 'EUR'
                ? '€ ' + amount.toLocaleString('{{ app()->getLocale() === 'nl' ? 'nl-NL' : 'en-US' }}', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : new Intl.NumberFormat('{{ app()->getLocale() }}', { style: 'currency', currency: code }).format(amount);
            const euro = c => view
                ? money(c * view.exchange_rate / 100, view.code)
                : money(c / 100, priceCurrency);

            async function refresh() {
                const data = new FormData(form);
                const arrival = data.get('arrival'), departure = data.get('departure');
                if (!arrival || !departure) { button.disabled = true; return; }

                overview.classList.add('hidden');
                error.classList.add('hidden');

                try {
                    const params = new URLSearchParams({ arrival, departure, guests: data.get('guests') });
                    const res = await fetch(`{{ route('api.properties.quote', $property) }}?` + params);
                    const json = await res.json();

                    if (!json.available) {
                        error.textContent = (json.reasons || [@json(__('This period is (partly) unavailable.'))]).join(' ');
                        error.classList.remove('hidden');
                        button.disabled = true;
                        return;
                    }

                    // Price lines such as a discount name are typed in the admin: text, not HTML.
                    const text = (value) => { const span = document.createElement('span'); span.textContent = value ?? ''; return span.innerHTML; };
                    overview.innerHTML = json.quote.lines
                        .map(l => `<div class="flex justify-between"><span class="text-stone-600 underline decoration-stone-300 underline-offset-2">${text(l.label)}</span><span>${euro(l.amount_cents)}</span></div>`)
                        .join('')
                        + `<div class="mt-2 flex justify-between border-t border-stone-200 pt-3 text-base font-semibold"><span>{{ __('Total') }}</span><span>${euro(json.quote.total_cents)}</span></div>`
                        + (json.quote.down_payment_cents < json.quote.total_cents
                            ? `<div class="flex justify-between text-stone-500"><span>{{ __('Due now (down payment)') }}</span><span>${euro(json.quote.down_payment_cents)}</span></div>`
                            : '');
                    overview.classList.remove('hidden');
                    button.disabled = false;
                } catch {
                    // fail silently; the server validates anyway
                }
            }

            form.querySelectorAll('input, select').forEach(el => el.addEventListener('change', refresh));
            refresh();
        })();
    </script>
</x-site-layout>
