<?php

namespace App\Models;

use App\Models\Concerns\HasTranslatedSlug;
use App\Models\Concerns\HasTranslations;
use App\Support\Languages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Free-form pages (about us, FAQ, privacy policy, …). Slugs live at root
 * level next to the property slugs; a property always wins over a page with
 * the same slug (see routes/web.php).
 */
#[Fillable([
    'slug', 'slug_translations', 'title', 'title_translations', 'content', 'content_translations',
    'meta_title', 'meta_title_translations', 'meta_description', 'meta_description_translations',
    'hero_images', 'published', 'in_menu', 'in_footer', 'sort',
])]
class Page extends Model
{
    use HasTranslatedSlug, HasTranslations;

    protected function casts(): array
    {
        return [
            'slug_translations' => 'array',
            'title_translations' => 'array',
            'content_translations' => 'array',
            'meta_title_translations' => 'array',
            'meta_description_translations' => 'array',
            'hero_images' => 'array',
            'published' => 'boolean',
            'in_menu' => 'boolean',
            'in_footer' => 'boolean',
        ];
    }

    protected function slugSource(): string
    {
        return 'title';
    }

    protected static function booted(): void
    {
        static::creating(function (self $page) {
            $page->slug = $page->slug ?: Str::slug($page->title);
        });
    }

    /**
     * Cleans the hero list from the repeater (ported from devsite's
     * PageController): only items with a photo, titles limited to known
     * languages, position from the fixed list.
     *
     * @return array<int, array{image: string, image_mobile: string, title: array<string, string>, link: string, position: string}>
     */
    public static function cleanHeroes(array $items): array
    {
        $positions = ['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'];
        $locales = Languages::available();
        $heroImages = [];

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['image']) || ! is_string($item['image'])) {
                continue;
            }
            $title = [];
            foreach ((array) ($item['title'] ?? []) as $loc => $value) {
                if (in_array($loc, $locales, true) && trim((string) $value) !== '') {
                    $title[$loc] = trim((string) $value);
                }
            }
            $heroImages[] = [
                'image' => $item['image'],
                'image_mobile' => (string) ($item['image_mobile'] ?? ''),
                'title' => $title,
                'link' => (string) ($item['link'] ?? ''),
                'position' => in_array($item['position'] ?? '', $positions, true) ? $item['position'] : 'center',
            ];
        }

        return $heroImages;
    }

    /** Title on a hero photo in the visitor's language (ported from devsite). */
    public static function heroTitle(array $hero, ?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();
        $title = $hero['title'] ?? [];
        if (is_array($title)) {
            return $title[$locale] ?? $title[config('app.locale')] ?? null;
        }

        return $title ?: null;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    /**
     * The pages for the header or footer menu, in the configured order.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function menuItems(string $spot = 'menu')
    {
        return static::query()
            ->published()
            ->where($spot === 'footer' ? 'in_footer' : 'in_menu', true)
            ->orderBy('sort')
            ->orderBy('title')
            ->get();
    }
}
