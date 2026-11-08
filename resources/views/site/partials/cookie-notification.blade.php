{{-- Tracking/marketing scripts pasted by the administrator, loaded server-side
     only if there is consent for them (or the notification is off). --}}
{!! \App\Support\CookieConsent::scripts() !!}

{{-- GDPR cookie notification. Self-contained (own css/js, cc prefix) so it works in every
     layout. The choice is stored in the separate cookie 'cookie_consent'. --}}
@if (\App\Support\CookieConsent::active())
    @php
        $ccPrivacy = \App\Support\SocialChannels::safeUrl(\App\Models\Setting::get('cookie', 'privacy_url'));
        $ccMarketing = \App\Support\CookieConsent::marketingOn();
        $ccTitle = \App\Models\Setting::localized('cookie', 'title') ?: __('This site uses cookies');
        $ccText = \App\Models\Setting::localized('cookie', 'text') ?: __('We use necessary cookies to make the site work. With your consent we also place cookies for statistics.');
    @endphp

    <style>
        .cc-banner{position:fixed;left:1rem;right:1rem;bottom:1rem;z-index:9999;max-width:32rem;margin-inline:auto;background:#fff;color:#1c1917;border:1px solid #e7e5e4;border-radius:1rem;box-shadow:0 20px 45px -20px rgba(0,0,0,.45);padding:1.25rem 1.35rem;font-size:.9rem;line-height:1.5}
        .cc-banner h2{font-size:1rem;font-weight:700;margin:0 0 .35rem}
        .cc-banner a{color:inherit;text-decoration:underline}
        .cc-actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}
        .cc-btn{cursor:pointer;border:0;border-radius:.7rem;padding:.55rem 1.1rem;font-size:.85rem;font-weight:600}
        .cc-btn-primary{background:var(--color-accent,#e11d48);color:#fff}
        .cc-btn-ghost{background:transparent;border:1px solid #d6d3d1;color:inherit}
        .cc-prefs{margin-top:.9rem;display:none;flex-direction:column;gap:.55rem}
        .cc-prefs.cc-open{display:flex}
        .cc-row{display:flex;align-items:flex-start;gap:.5rem}
        .cc-row input{margin-top:.2rem}
        .cc-reopen{position:fixed;left:1rem;bottom:1rem;z-index:9998;background:#fff;border:1px solid #e7e5e4;border-radius:999px;width:2.6rem;height:2.6rem;cursor:pointer;font-size:1.2rem;box-shadow:0 8px 20px -10px rgba(0,0,0,.5);display:none}
    </style>

    <div class="cc-banner" id="cc-banner" role="dialog" aria-live="polite" aria-label="{{ $ccTitle }}" hidden>
        <h2>{{ $ccTitle }}</h2>
        <p>
            {{ $ccText }}
            @if ($ccPrivacy)
                <a href="{{ $ccPrivacy }}" target="_blank" rel="noopener">{{ __('More information') }}</a>
            @endif
        </p>

        <div class="cc-prefs" id="cc-prefs">
            <label class="cc-row"><input type="checkbox" checked disabled> <span><strong>{{ __('Necessary') }}</strong> — {{ __('Needed to make the site work (always on).') }}</span></label>
            <label class="cc-row"><input type="checkbox" id="cc-statistics"> <span><strong>{{ __('Statistics') }}</strong> — {{ __('Anonymously measure how the site is used.') }}</span></label>
            @if ($ccMarketing)
                <label class="cc-row"><input type="checkbox" id="cc-marketing"> <span><strong>{{ __('Marketing') }}</strong> — {{ __('For personalised ads.') }}</span></label>
            @endif
        </div>

        <div class="cc-actions">
            <button type="button" class="cc-btn cc-btn-primary" data-cc="all">{{ __('Accept all') }}</button>
            <button type="button" class="cc-btn cc-btn-ghost" data-cc="necessary">{{ __('Necessary only') }}</button>
            <button type="button" class="cc-btn cc-btn-ghost" data-cc="prefs">{{ __('Preferences') }}</button>
            <button type="button" class="cc-btn cc-btn-primary" data-cc="save" style="display:none">{{ __('Save preferences') }}</button>
        </div>
    </div>

    <button type="button" class="cc-reopen" id="cc-reopen" title="{{ __('Cookie preferences') }}" aria-label="{{ __('Cookie preferences') }}">🍪</button>

    <script>
    (function () {
        var NAME = 'cookie_consent', MARKETING = @json($ccMarketing);
        var banner = document.getElementById('cc-banner'), reopen = document.getElementById('cc-reopen'),
            prefs = document.getElementById('cc-prefs');

        function read() {
            var m = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]+)/);
            if (!m) return null;
            try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
        }
        function write(obj) {
            obj.v = 1;
            var d = new Date(); d.setMonth(d.getMonth() + 6);
            document.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(obj)) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
        }
        function show() { banner.hidden = false; reopen.style.display = 'none'; }
        function save(choice) {
            write(choice);
            window.cookieConsent = choice;      // available before custom scripts
            location.reload();                  // the server renders gated scripts again
        }

        var cur = read();
        if (cur) {
            var s = document.getElementById('cc-statistics'); if (s) s.checked = !!cur.statistics;
            var mk = document.getElementById('cc-marketing'); if (mk) mk.checked = !!cur.marketing;
        }
        if (!cur || cur.v !== 1) show(); else { banner.hidden = true; reopen.style.display = 'block'; }

        banner.addEventListener('click', function (e) {
            var act = e.target.getAttribute('data-cc');
            if (!act) return;
            if (act === 'all') save({ necessary: true, statistics: true, marketing: MARKETING });
            else if (act === 'necessary') save({ necessary: true, statistics: false, marketing: false });
            else if (act === 'prefs') {
                prefs.classList.add('cc-open');
                banner.querySelector('[data-cc="save"]').style.display = '';
            } else if (act === 'save') {
                save({
                    necessary: true,
                    statistics: !!(document.getElementById('cc-statistics') || {}).checked,
                    marketing: MARKETING && !!(document.getElementById('cc-marketing') || {}).checked,
                });
            }
        });
        reopen.addEventListener('click', show);
        window.openCookieSettings = show;      // a custom link can call this
    })();
    </script>
@endif
