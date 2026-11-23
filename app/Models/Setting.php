<?php

namespace App\Models;

use App\Support\RequestMemory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['group', 'key', 'value'])]
class Setting extends Model
{
    /** Key of the cache and of the request memory. */
    private const ALL = 'settings.all';

    /**
     * Passwords and API keys: stored encrypted (with APP_KEY), so the
     * database, a backup or the settings cache never holds them readable.
     * get() and set() do this themselves; the rest of the code does not
     * notice. The admin never shows them again either (Settings).
     */
    public const SECRETS = [
        'ai.claude_api_key', 'ai.openai_api_key', 'ai.gemini_api_key',
        'import.apify_token',
        'locks.seam_api_key',
        'mail.password',
        'payments.mollie_api_key', 'payments.stripe_secret_key', 'payments.stripe_webhook_secret',
        'prices.pricelabs_api_key', 'prices.beyond_api_key', 'prices.wheelhouse_api_key',
        'translations.deepl_api_key',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => static::forgets());
        static::deleted(fn () => static::forgets());
    }

    public static function get(string $group, string $key, ?string $default = null): ?string
    {
        $value = static::allCached()["{$group}.{$key}"] ?? null;

        if ($value !== null && static::isSecret($group, $key)) {
            $value = static::reveal($value);
        }

        return $value ?? $default;
    }

    public static function isSecret(string $group, string $key): bool
    {
        return in_array("{$group}.{$key}", self::SECRETS, true);
    }

    /**
     * The readable value of a stored secret. A value that cannot be decrypted
     * (APP_KEY changed) counts as not set, rather than passing on gibberish;
     * a value from before encryption (plain text) is returned as it is.
     */
    private static function reveal(string $stored): ?string
    {
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            $payload = json_decode((string) base64_decode($stored, true), true);

            return is_array($payload) && isset($payload['iv'], $payload['mac']) ? null : $stored;
        }
    }

    /**
     * Multilingual setting: first the value for the current language
     * (e.g. slogan_en), otherwise the untranslated value, otherwise the default.
     */
    public static function localized(string $group, string $key, ?string $default = null): ?string
    {
        return static::get($group, $key.'_'.app()->getLocale())
            ?: static::get($group, $key)
            ?: $default;
    }

    public static function set(string $group, string $key, ?string $value): void
    {
        if (static::isSecret($group, $key) && filled($value)) {
            $value = Crypt::encryptString($value);
        }

        static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );
    }

    public static function forgets(): void
    {
        Cache::forget(self::ALL);
        RequestMemory::forgets(self::ALL);
        // "Show one accommodation on the main domain" may have changed.
        \App\Support\LocalizedUrl::forgetHomeProperty();
    }

    /**
     * All settings as "group.key" => value: one cache item, read once
     * per request.
     *
     * Previously every setting had its own cache item. With the database cache
     * every lookup still cost a query, and a setting that did not exist
     * was stored as null, which Laravel treats as "not in the cache":
     * three queries per missing setting on every page.
     *
     * @return array<string, ?string>
     */
    private static function allCached(): array
    {
        return RequestMemory::remember(self::ALL, function (): array {
            $all = Cache::rememberForever(self::ALL, fn (): array => static::offDatabase());

            if (! is_array($all)) {
                // Something other than a list in the cache: discard and start over.
                Cache::forget(self::ALL);
                $all = Cache::rememberForever(self::ALL, fn (): array => static::offDatabase());
            }

            return $all;
        });
    }

    /**
     * @return array<string, ?string>
     */
    private static function offDatabase(): array
    {
        return static::query()
            ->toBase()
            ->get(['group', 'key', 'value'])
            ->mapWithKeys(fn (object $row) => ["{$row->group}.{$row->key}" => $row->value])
            ->all();
    }
}
