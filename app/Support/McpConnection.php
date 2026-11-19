<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use phpseclib4\Crypt\RSA;

/**
 * The AI connection: owners (and, within their rights, staff and hosts) work
 * on the site from an AI app such as Claude or ChatGPT, through MCP
 * (App\Mcp\Servers\BookingServer).
 *
 * Off by default. The owner switches it on in Settings → AI connection and
 * chooses which apps may connect; from then on such an app connects at url(),
 * and the person logs in to the admin and clicks "Allow" (OAuth through
 * Passport). Switching it off revokes every connection at once.
 */
final class McpConnection
{
    /** How long an access token lasts; the app renews it with the refresh token. */
    public const ACCESS_TOKEN_HOURS = 24;

    /** After this many days without use the person has to connect again. */
    public const REFRESH_TOKEN_DAYS = 60;

    /**
     * The apps an owner can allow, by where each sends the person back after
     * "Allow": web addresses (any path on them) and app links (cursor://).
     * Only these, plus addresses the owner adds, can register: nobody can
     * collect the access code on a look-alike website.
     *
     * @var array<string, array{domains?: list<string>, schemes?: list<string>}>
     */
    public const APPS = [
        'claude' => ['domains' => ['https://claude.ai', 'https://claude.com']],
        'chatgpt' => ['domains' => ['https://chatgpt.com', 'https://chat.openai.com']],
        'vscode' => ['domains' => ['https://vscode.dev', 'https://insiders.vscode.dev'], 'schemes' => ['vscode', 'vscode-insiders']],
        'cursor' => ['schemes' => ['cursor']],
        // Claude Code, Gemini CLI, Codex and other apps on the person's own computer.
        'local' => ['domains' => ['http://localhost']],
    ];

    public static function enabled(): bool
    {
        return Setting::get('mcp', 'enabled') === '1';
    }

    /** The address to paste into the AI app as a connector (MCP server). */
    public static function url(): string
    {
        return url('/mcp');
    }

    /** @return array<string, string> app key => name, for the settings */
    public static function appOptions(): array
    {
        return [
            'claude' => 'Claude',
            'chatgpt' => 'ChatGPT',
            'vscode' => 'VS Code',
            'cursor' => 'Cursor',
            'local' => __('Apps on your own computer (Claude Code, Gemini CLI, Codex and others)'),
        ];
    }

    /**
     * The apps the owner allows; not chosen yet = all known apps.
     *
     * @return list<string>
     */
    public static function allowedApps(): array
    {
        $stored = Setting::get('mcp', 'apps');

        if ($stored === null) {
            return array_keys(self::APPS);
        }

        return array_values(array_intersect(explode(',', $stored), array_keys(self::APPS)));
    }

    /**
     * Web addresses of other apps the owner allows, one per line.
     *
     * @return list<string>
     */
    public static function otherAddresses(): array
    {
        return collect(preg_split('/\R/', (string) Setting::get('mcp', 'other_addresses')))
            ->map(fn (string $line) => self::origin($line))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Only the scheme, host and port of an https address (or null when it is
     * none): "https://app.example.com/oauth/callback" becomes
     * "https://app.example.com".
     */
    public static function origin(string $address): ?string
    {
        $parts = parse_url(trim($address));

        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Where an app may send the person back, for laravel/mcp's registration
     * (config mcp.redirect_domains and mcp.custom_schemes). Set on every
     * request to the connection by EnsureMcpEnabled.
     */
    public static function applyRedirectRules(): void
    {
        $apps = collect(self::APPS)->only(self::allowedApps());

        config([
            'mcp.redirect_domains' => $apps->pluck('domains')->filter()->flatten()->merge(self::otherAddresses())->unique()->values()->all(),
            'mcp.custom_schemes' => $apps->pluck('schemes')->filter()->flatten()->unique()->values()->all(),
        ]);
    }

    /**
     * May an app send the person back to this address? The same rules as at
     * registration, so an app the owner no longer allows cannot log in again.
     */
    public static function allowsRedirect(string $uri): bool
    {
        self::applyRedirectRules();
        $scheme = parse_url($uri, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return in_array($scheme, config('mcp.custom_schemes'), true);
        }

        $host = parse_url($uri, PHP_URL_HOST);

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return in_array('http://localhost', config('mcp.redirect_domains'), true);
        }

        return Str::startsWith($uri, array_map(fn (string $domain) => rtrim($domain, '/').'/', config('mcp.redirect_domains')));
    }

    /**
     * After the switch: switched on, make sure the signing keys exist;
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
            'app' => (string) ($token->client?->name ?? '?'),
            'since' => $token->created_at,
        ])->values();
    }

    /** Ends every connection: each app has to connect and be allowed again. */
    public static function disconnectAll(): int
    {
        $count = Token::query()->where('revoked', false)->update(['revoked' => true]);
        RefreshToken::query()->where('revoked', false)->update(['revoked' => true]);
        AuthCode::query()->where('revoked', false)->update(['revoked' => true]);

        return $count;
    }

    /**
     * Tokens that are not revoked and still usable: the access token itself,
     * or its refresh token with which the app gets a new one.
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
