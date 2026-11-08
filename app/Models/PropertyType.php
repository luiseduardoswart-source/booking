<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Accommodation type (holiday home, apartment, room, …) — manageable in the
 * admin instead of a fixed list. `properties.type` refers to the
 * slug; if a type disappears, the display falls back gracefully to the slug.
 */
#[Fillable(['slug', 'name', 'name_translations'])]
class PropertyType extends Model
{
    use HasTranslations;

    /**
     * The types every installation gets: slug => English name (the
     * source language, see Languages::SOURCE). The translations are in the language files,
     * including nl.json. The slug stays fixed: it is stored in properties.type.
     */
    public const DEFAULT = [
        'holiday_home' => 'Holiday home',
        'apartment' => 'Apartment',
        'room' => 'Room',
    ];

    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $type) {
            $type->slug = $type->slug ?: Str::slug($type->name);
        });
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'type', 'slug');
    }

    /**
     * Name in the visitor's language: own translation → built-in
     * translation (covers the default types) → the English name.
     */
    public function localizedName(): string
    {
        return (string) $this->localized('name');
    }

    /** The default types are in the language files under their English name. */
    public function translateViaLanguageFile(string $field): bool
    {
        return $field === 'name';
    }

    /**
     * Option list for forms: slug => name in the current language.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        // By name in the current language, not by the English name in the database.
        return static::query()->get()
            ->mapWithKeys(fn (self $type) => [$type->slug => $type->localizedName()])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    /**
     * Display name for a slug, with a graceful fallback if the type no longer
     * exists. One query per request.
     */
    public static function nameFor(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return '';
        }

        $cache = once(fn () => static::query()->get()->keyBy('slug'));

        $type = $cache[$slug] ?? null;

        if ($type !== null) {
            return $type->localizedName();
        }

        return isset(self::DEFAULT[$slug]) ? __(self::DEFAULT[$slug]) : ucfirst($slug);
    }
}
