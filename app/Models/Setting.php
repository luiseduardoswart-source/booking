<?php

namespace App\Models;

use App\Support\RequestMemory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['group', 'key', 'value'])]
class Setting extends Model
{
    /** Key of the cache and of the request memory. */
    private const ALL = 'settings.all';

    protected static function booted(): void
    {
        static::saved(fn () => static::forgets());
        static::deleted(fn () => static::forgets());
    }

    public static function get(string $group, string $key, ?string $default = null): ?string
    {
        return static::allCached()["{$group}.{$key}"] ?? $default;
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
        static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );
    }

    public static function forgets(): void
    {
        Cache::forget(self::ALL);
        RequestMemory::forgets(self::ALL);
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
