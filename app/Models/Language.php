<?php

namespace App\Models;

use App\Support\RequestMemory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A language the website can show. Taken over from the devsite project: the
 * owner manages the languages themselves instead of them being fixed in the code.
 *
 * Note: adding a language does not create translations yet. The texts of the
 * software come from lang/<code>.json; if that file is missing, everything falls
 * back to the source language. Fill it with `php artisan lang:translate <code>`.
 */
#[Fillable(['code', 'name', 'active', 'is_default', 'sort'])]
class Language extends Model
{
    /** Cache key of the list; AdminLanguage has its own key. */
    protected const CACHE = 'languages.all';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['active' => 'bool', 'is_default' => 'bool'];
    }

    protected static function booted(): void
    {
        // The list is requested on every request; fetching it again after
        // every change is enough, so we cache until something changes.
        static::saved(fn () => static::forgets());
        static::deleted(fn () => static::forgets());
    }

    public static function forgets(): void
    {
        Cache::forget(static::CACHE);
        RequestMemory::forgets(static::CACHE);
    }

    /**
     * All languages, in order.
     *
     * In the cache we deliberately store plain rows and no model objects. Those
     * have to be serialised in a file cache, and a half-
     * written or unreadable file then yields an unusable object that
     * takes down the whole admin screen. Rows are small and cannot break.
     *
     * @return Collection<int, static>
     */
    public static function allCached()
    {
        // Requested dozens of times per page: once per request is enough.
        return RequestMemory::remember(static::CACHE, fn () => static::offCache());
    }

    /**
     * @return Collection<int, static>
     */
    private static function offCache()
    {
        try {
            $rows = Cache::rememberForever(static::CACHE, fn () => static::rows());

            if (is_array($rows)) {
                return static::hydrate($rows);
            }

            // Something other than rows in the cache (for example an old
            // value): discard it and simply fetch again.
            static::forgets();
        } catch (\Throwable) {
            static::forgets();
        }

        return static::hydrate(static::rows());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function rows(): array
    {
        return static::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (self $language) => $language->getAttributes())
            ->all();
    }
}
