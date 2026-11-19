<?php

namespace App\Http\Middleware;

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
        // without a match Symfony returns the first language in the list.
        $locale = $this->chosen($request) ?? $request->getPreferredLanguage([$default, ...$active]);

        return in_array($locale, $active, true) ? $locale : $default;
    }

    /**
     * The language someone chose themselves, for this session. A translated address counts
     * as a choice: whoever arrives on /casa-frente-al-mar stays in Spanish.
     */
    protected function chosen(Request $request): ?string
    {
        $language = Slugs::languageOfRequest($request);

        if ($language !== null && in_array($language, Languages::active(), true)) {
            session()->put('locale', $language);

            return $language;
        }

        return session('locale');
    }
}
