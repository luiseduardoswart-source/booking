<?php

namespace App\Support;

use App\Models\Setting;

/**
 * GDPR cookie notice: determines whether the banner is needed and which scripts
 * pasted by the admin (statistics/marketing) may be loaded based on
 * the visitor's consent. The choice is stored in a separate, unencrypted
 * cookie 'cookie_consent' (JS sets it; the server reads it) — which is why that
 * cookie is excluded from cookie encryption in bootstrap/app.php.
 *
 * "Necessary" (session, currency/language, cart) is exempt under the GDPR
 * and is always on; statistics/marketing only with explicit consent.
 */
class CookieConsent
{
    /**
     * The cookie categories; 'marketing' only if it is enabled.
     *
     * @return string[]
     */
    public static function categories(): array
    {
        $categories = ['necessary', 'statistics'];

        if (self::marketingOn()) {
            $categories[] = 'marketing';
        }

        return $categories;
    }

    /**
     * Should the banner be shown? Only if the notice is on AND there is
     * actually something to ask consent for (a statistics script
     * or the marketing category). Without such cookies a banner is not needed.
     */
    public static function active(): bool
    {
        return Setting::get('cookie', 'on', '0') === '1'
            && (filled(Setting::get('cookie', 'code_statistics')) || self::marketingOn());
    }

    public static function marketingOn(): bool
    {
        return Setting::get('cookie', 'marketing_on', '0') === '1';
    }

    /**
     * The visitor's choice from the cookie (set by JS), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function choice(): ?array
    {
        $raw = request()?->cookie('cookie_consent');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * May a category be placed? 'necessary' always; the others only
     * with consent. If the notice is off, everything is allowed (then it is
     * the admin's responsibility).
     */
    public static function is(string $category): bool
    {
        if ($category === 'necessary' || ! self::active()) {
            return true;
        }

        return (bool) (self::choice()[$category] ?? false);
    }

    /**
     * The pasted scripts per category, but only those for which consent
     * has been given. This way the admin does not have to hang their tracking/marketing
     * code behind a consent check themselves — they paste it in the right box.
     */
    public static function scripts(): string
    {
        $output = '';

        foreach (['statistics', 'marketing'] as $category) {
            $code = trim((string) Setting::get('cookie', "code_{$category}"));

            if ($code !== '' && self::is($category)) {
                $output .= "\n".$code;
            }
        }

        return $output;
    }
}
