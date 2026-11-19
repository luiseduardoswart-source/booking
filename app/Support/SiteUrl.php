<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

/**
 * Addresses on this site itself.
 */
final class SiteUrl
{
    /**
     * Live, every link (also in mails) uses APP_URL, never the Host header of
     * the request: otherwise a forged header could put another domain in a
     * login link mailed to a guest. The same address CanonicalHost uses.
     */
    public static function pinToAppUrl(): void
    {
        $appUrl = (string) config('app.url');

        if (! app()->environment('production') || blank(parse_url($appUrl, PHP_URL_HOST))) {
            return;
        }

        URL::forceRootUrl(rtrim($appUrl, '/'));

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * The previous page, to go back to after switching language or currency.
     * Only a page of this site: the previous address can come from the
     * Referer header, which any website can set, and following it would make
     * this site a redirect to anywhere.
     */
    public static function previous(): string
    {
        $previous = url()->previous(route('home'));

        return parse_url($previous, PHP_URL_HOST) === request()->getHost() ? $previous : route('home');
    }
}
