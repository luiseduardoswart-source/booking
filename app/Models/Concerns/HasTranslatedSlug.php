<?php

namespace App\Models\Concerns;

use App\Support\Slugs;
use Illuminate\Support\Str;

/**
 * A separate address per language: `slug` is the base address and `slug_translations`
 * has a slug per language code. A translated address determines the page's language
 * (see SetLocale); the base address is not tied to any language. The column must be
 * in the casts as 'array'.
 */
trait HasTranslatedSlug
{
    /** The translatable field a missing slug is generated from. */
    abstract protected function slugSource(): string;

    public static function bootHasTranslatedSlug(): void
    {
        static::saving(fn (self $model) => $model->cleanUpSlugs());
    }

    /** The address in a language, or the base address if that language has none. */
    public function slugFor(?string $locale = null): string
    {
        return ($this->slug_translations[$locale ?? app()->getLocale()] ?? null) ?: $this->slug;
    }

    /** The language this address belongs to; null for the base address. */
    public function languageOfSlug(string $slug): ?string
    {
        $language = array_search($slug, $this->slug_translations ?? [], true);

        return is_string($language) ? $language : null;
    }

    /**
     * By any address, in any language. A slug consists only of plain characters,
     * so it appears literally in the JSON; the check afterwards rules out coincidence.
     */
    public static function findBySlug(string $slug): ?static
    {
        return static::query()->where('slug', $slug)->first()
            ?? static::query()
                ->where('slug_translations', 'like', '%"'.$slug.'"%')
                ->get()
                ->first(fn (self $model) => $model->languageOfSlug($slug) !== null);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $field === 'slug' ? static::findBySlug($value) : parent::resolveRouteBinding($value, $field);
    }

    /**
     * Generates missing addresses from the translated text. Each address belongs to
     * one language: duplicates and the base address itself are dropped, otherwise an
     * address would pick the wrong language.
     */
    private function cleanUpSlugs(): void
    {
        $slugs = $this->slug_translations ?? [];

        foreach ($this->{$this->slugSource().'_translations'} ?? [] as $locale => $text) {
            if (blank($slugs[$locale] ?? null) && filled($text) && ($slug = Str::slug($text, '-', $locale)) !== '') {
                $slugs[$locale] = $slug === $this->slug ? $slug : Slugs::free($slug, $this);
            }
        }

        $slugs = array_unique(array_filter($slugs, fn (?string $slug) => filled($slug) && $slug !== $this->slug));

        $this->slug_translations = $slugs ?: null;
    }
}
