<?php

namespace App\Models\Concerns;

use App\Support\Languages;
use App\Support\RichText;

/**
 * Translatable fields on a model. For each field the source text is in the column itself (e.g. `bio`) and the translations in a JSON column next to it
 * (`bio_translations`, with one text per language code). That column must be
 * cast as 'array'.
 *
 * One place for what used to be copied per model (amenities, hosts,
 * pages, accommodation types).
 */
trait HasTranslations
{
    /**
     * A translatable field in the visitor's language, or in $locale.
     *
     * Order: the own translation for that language; then, only for fields that
     * translateViaLanguageFile() names, the built-in translation from the
     * language files; then the source text.
     */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $custom = $this->{"{$field}_translations"}[$locale] ?? null;

        // An emptied text editor ("<p></p>") is no translation: show the source text.
        if (is_string($custom) ? RichText::filled($custom) : filled($custom)) {
            return $custom;
        }

        $source = $this->{$field};

        return $this->translateViaLanguageFile($field) && filled($source)
            ? __($source, [], $locale)
            : $source;
    }

    /**
     * The own text of a field in one language, without falling back: the
     * column for the source language, otherwise its translation (null = none).
     */
    public function ownText(string $field, string $locale): ?string
    {
        return $locale === $this->sourceLanguage()
            ? $this->{$field}
            : ($this->{"{$field}_translations"}[$locale] ?? null);
    }

    /** Sets the own text of a field in one language; empty removes a translation. */
    public function setOwnText(string $field, string $locale, ?string $text): void
    {
        if ($locale === $this->sourceLanguage()) {
            $this->{$field} = $text;

            return;
        }

        $translations = $this->{"{$field}_translations"} ?? [];
        $translations[$locale] = $text;
        $this->{"{$field}_translations"} = array_filter($translations, fn ($value) => filled($value));
    }

    /** The language of the text in the column itself. */
    public function sourceLanguage(): string
    {
        return Languages::SOURCE;
    }

    /**
     * May the source text of this field also be a key in the
     * language files? If so, it already has a built-in translation
     * (the default amenities and types) and automatic translation skips
     * those languages. Off by default; a model enables it per field.
     */
    public function translateViaLanguageFile(string $field): bool
    {
        return false;
    }
}
