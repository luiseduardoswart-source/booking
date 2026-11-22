<?php

namespace App\Http\Middleware;

use App\Support\LocalizedUrl;
use App\Support\Slugs;
use App\Support\Languages;
use App\Support\WebsiteTexts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;

/**
 * The language of the website. The admin has its own list, see SetAdminLocale.
 */
class SetLocale
{
    /** Request attribute: the address itself names the language (a prefix or a translated slug). */
    public const FROM_ADDRESS = 'language_from_address';

    /** @var class-string<Languages> */
    protected string $languages = Languages::class;

    public function handle(Request $request, Closure $next)
    {
        WebsiteTexts::usage($this->languages === Languages::class);

        $locale = $this->locale($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);

        return $next($request);
    }

    /** The language for this request, from this middleware's list of languages. */
    protected function locale(Request $request): string
    {
        $languages = $this->languages;
        $active = $languages::active();
        $default = $languages::default();

        // First visit: look at the browser language. The default goes first, because
        // without a match Symfony returns the first language in the list. Not on the
        // pages search engines index: there the address decides (the default
        // without a prefix). Google advises against sending visitors elsewhere by
        // their browser language: they, and search engines, would miss the other
        // versions. A language the visitor chose themselves does still count.
        $chosen = $this->chosen($request);
        $locale = $chosen ?? (LocalizedUrl::isIndexable($request) && $languages === Languages::class
            ? $default
            : $request->getPreferredLanguage([$default, ...$active]));

        return in_array($locale, $active, true) ? $locale : $default;
    }

    /**
     * The language someone chose themselves, for this session. An address with
     * a language counts as a choice: whoever arrives on /es/casa-frente-al-mar
     * (or the old /casa-frente-al-mar) stays in Spanish.
     */
    protected function chosen(Request $request): ?string
    {
        // The prefix of the language versions (/es/...). The controllers do not
        // take it, so it leaves the route here.
        $route = $request->route();
        $prefix = $route?->parameter('lang');

        if (is_string($prefix)) {
            $route->forgetParameter('lang');
            $request->attributes->set(self::FROM_ADDRESS, true);

            if (in_array($prefix, Languages::active(), true)) {
                session()->put('locale', $prefix);

                return $prefix;
            }

            // A language that is switched off: the default, and LanguageAddress
            // moves the address there for good.
            return Languages::default();
        }

        $language = Slugs::languageOfRequest($request);

        if ($language !== null) {
            $request->attributes->set(self::FROM_ADDRESS, true);
        }

        if ($language !== null && in_array($language, Languages::active(), true)) {
            session()->put('locale', $language);

            return $language;
        }

        return session('locale');
    }
}
