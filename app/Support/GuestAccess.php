<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\GuestLogin;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who may see which booking on the site, without a password.
 *
 * - The button in every guest email: a signed link to that one booking,
 *   valid until three months after departure.
 * - "My bookings": the guest asks for a login link by email (one-time, 15
 *   minutes). Using it logs them in for 90 days; the browser keeps a cookie
 *   with a random code. Only a hash is stored, so the login can be revoked
 *   (log out, log out everywhere, or by the owner).
 *
 * The email address never goes into a link or a cookie: only random codes.
 */
final class GuestAccess
{
    public const LINK_MINUTES = 15;

    public const LOGIN_DAYS = 90;

    /** The button in the emails stays valid this long after departure; after that, "My bookings". */
    public const BOOKING_LINK_MONTHS = 3;

    public const COOKIE = 'guest_login';

    private const SESSION_LOGIN = 'guest_login_id';

    /** Bookings opened through the link in an email, during this visit. */
    private const SESSION_BOOKINGS = 'guest_bookings';

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** The signed link to one booking, for the emails. */
    public static function bookingUrl(Booking $booking): string
    {
        return URL::temporarySignedRoute(
            'guest.booking',
            CarbonImmutable::parse($booking->departure_date)->endOfDay()->addMonthsNoOverflow(self::BOOKING_LINK_MONTHS),
            ['booking' => $booking->id],
        );
    }

    /** A new one-time login link for this address; returns the code for the URL. */
    public static function issueLink(string $email): string
    {
        // Clear what can no longer be used, so the table stays small.
        GuestLogin::query()->where('expires_at', '<', now())->delete();

        $token = Str::random(64);

        GuestLogin::create([
            'email' => self::normalise($email),
            'kind' => GuestLogin::LINK,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::LINK_MINUTES),
        ]);

        return $token;
    }

    /**
     * Uses a login link: once, and only within its time. Returns the cookie
     * that keeps the guest logged in, or null for an unknown, used or expired link.
     */
    public static function redeem(Request $request, string $token): ?Cookie
    {
        $link = GuestLogin::query()
            ->where('kind', GuestLogin::LINK)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->first();

        if ($link === null) {
            return null;
        }

        // Claim it in one statement: two requests with the same link at the
        // same moment must not both log in.
        $claimed = GuestLogin::query()->whereKey($link->id)->whereNull('used_at')->update(['used_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        $code = Str::random(64);
        $login = GuestLogin::create([
            'email' => $link->email,
            'kind' => GuestLogin::SESSION,
            'token_hash' => hash('sha256', $code),
            'expires_at' => now()->addDays(self::LOGIN_DAYS),
            'last_used_at' => now(),
        ]);

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_LOGIN, $login->id);

        return cookie(self::COOKIE, $code, self::LOGIN_DAYS * 24 * 60, secure: $request->isSecure(), httpOnly: true, sameSite: 'lax');
    }

    /** The current login (session first, otherwise the cookie), if still valid. */
    public static function login(Request $request): ?GuestLogin
    {
        $id = $request->session()->get(self::SESSION_LOGIN);
        $login = $id === null ? null : GuestLogin::query()->whereKey($id)->where('kind', GuestLogin::SESSION)->first();

        if ($login === null && is_string($code = $request->cookie(self::COOKIE)) && $code !== '') {
            $login = GuestLogin::query()
                ->where('kind', GuestLogin::SESSION)
                ->where('token_hash', hash('sha256', $code))
                ->first();
        }

        if ($login === null || $login->expires_at->isPast()) {
            $request->session()->forget(self::SESSION_LOGIN);

            return null;
        }

        $request->session()->put(self::SESSION_LOGIN, $login->id);

        return $login;
    }

    public static function email(Request $request): ?string
    {
        return self::login($request)?->email;
    }

    /** Through the link in an email: this booking may be seen during this visit. */
    public static function grant(Request $request, Booking $booking): void
    {
        $request->session()->put(self::SESSION_BOOKINGS, array_values(array_unique([
            ...(array) $request->session()->get(self::SESSION_BOOKINGS, []),
            $booking->id,
        ])));
    }

    public static function mayView(Request $request, Booking $booking): bool
    {
        if (in_array($booking->id, (array) $request->session()->get(self::SESSION_BOOKINGS, []), true)) {
            return true;
        }

        $email = self::email($request);

        return $email !== null && $email === self::normalise((string) $booking->guest_email);
    }

    /** Log out here, or on every device of this address. Returns the cookie that clears the browser's. */
    public static function logout(Request $request, bool $everywhere = false): Cookie
    {
        $login = self::login($request);

        if ($login !== null) {
            $everywhere ? self::revoke($login->email) : $login->delete();
        }

        $request->session()->forget([self::SESSION_LOGIN, self::SESSION_BOOKINGS]);
        $request->session()->regenerate();

        return cookie()->forget(self::COOKIE);
    }

    /** Ends every login and unused link of this address (also used by the owner). */
    public static function revoke(string $email): int
    {
        return GuestLogin::query()->where('email', self::normalise($email))->delete();
    }
}
