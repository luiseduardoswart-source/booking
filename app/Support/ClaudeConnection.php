<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use phpseclib4\Crypt\RSA;

/**
 * The Claude connection: owners (and, within their rights, staff and hosts)
 * work on the site from Claude through MCP (App\Mcp\Servers\BookingServer).
 *
 * Off by default. The owner switches it on in Settings → Claude; from then
 * on Claude can connect at url(), and the person logs in to the admin and
 * clicks "Allow" (OAuth through Passport). Switching it off revokes every
 * connection at once.
 */
final class ClaudeConnection
{
    /** How long an access token lasts; Claude renews it with the refresh token. */
    public const ACCESS_TOKEN_HOURS = 24;

    /** After this many days without use the person has to connect again. */
    public const REFRESH_TOKEN_DAYS = 60;

    public static function enabled(): bool
    {
        return Setting::get('claude', 'enabled') === '1';
    }

    /** The address to paste into Claude as a connector. */
    public static function url(): string
    {
        return url('/mcp');
    }

    /**
     * After Settings was saved: switched on, make sure the signing keys exist;
     * switched off, end every connection.
     */
    public static function switched(bool $wasEnabled): void
    {
        if (self::enabled()) {
            self::ensureKeys();
        } elseif ($wasEnabled) {
            self::disconnectAll();
        }
    }

    /**
     * The key pair Passport signs its tokens with, created here so no terminal
     * is needed (shared hosting). Same as `php artisan passport:keys`; the
     * files are in storage/, which updates and zip packages leave alone.
     */
    public static function ensureKeys(): void
    {
        $private = Passport::keyPath('oauth-private.key');
        $public = Passport::keyPath('oauth-public.key');

        if (filled(config('passport.private_key')) || (is_file($private) && is_file($public))) {
            return;
        }

        $key = RSA::createKey(4096);

        file_put_contents($public, (string) $key->getPublicKey());
        file_put_contents($private, (string) $key);

        // league/oauth2-server refuses a key that others can read.
        if (! windows_os()) {
            chmod($public, 0660);
            chmod($private, 0600);
        }
    }

    /**
     * The connections that still work: per person and app, when it was last
     * connected.
     *
     * @return Collection<int, array{user: string, app: string, since: Carbon}>
     */
    public static function connections(): Collection
    {
        $tokens = self::activeTokens()
            ->with('client:id,name')
            ->latest()
            ->get()
            ->unique(fn (Token $token) => $token->user_id.'|'.$token->client_id);

        // Not through Token::user(): that relation cannot be loaded in one go.
        $users = User::query()->whereIn('id', $tokens->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return $tokens->map(fn (Token $token) => [
            'user' => (string) ($users[$token->user_id]->name ?? $users[$token->user_id]->email ?? '?'),
            'app' => (string) ($token->client?->name ?? 'Claude'),
            'since' => $token->created_at,
        ])->values();
    }

    /** Ends every connection: Claude has to connect and be allowed again. */
    public static function disconnectAll(): int
    {
        $count = Token::query()->where('revoked', false)->update(['revoked' => true]);
        RefreshToken::query()->where('revoked', false)->update(['revoked' => true]);
        AuthCode::query()->where('revoked', false)->update(['revoked' => true]);

        return $count;
    }

    /**
     * Tokens that are not revoked and still usable: the access token itself,
     * or its refresh token with which Claude gets a new one.
     *
     * @return Builder<Token>
     */
    private static function activeTokens(): Builder
    {
        $refreshable = RefreshToken::query()
            ->select('access_token_id')
            ->where('revoked', false)
            ->where('expires_at', '>', now());

        return Token::query()
            ->where('revoked', false)
            ->where(fn ($query) => $query
                ->where('expires_at', '>', now())
                ->orWhereIn('id', $refreshable));
    }
}
