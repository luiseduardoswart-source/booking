<?php

namespace App\Support;

use App\Services\MachineTranslator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

/**
 * Automatically fill empty languages from the source text, via the
 * configured translation service. One place for what used to be copied per
 * admin screen.
 *
 * Existing translations are always kept: only what is empty gets filled.
 */
class Translations
{
    /**
     * The core, without saving anything: returns the translations of one field
     * with the missing languages filled in.
     *
     * @param  array<string, string>|null  $translations  language code => text
     * @param  (callable(string): bool)|null  $alreadyBuiltIn  language already has a built-in translation
     * @param  int  $count  incremented by the number of new translations
     * @return array<string, string>
     */
    public static function fillIn(string $source, ?array $translations, ?callable $alreadyBuiltIn = null, int &$count = 0, string $sourceLanguage = Languages::SOURCE): array
    {
        $translations ??= [];
        $source = trim($source);

        if ($source === '') {
            return $translations;
        }

        $translator = app(MachineTranslator::class);

        foreach (Languages::active() as $locale) {
            if ($locale === $sourceLanguage || filled($translations[$locale] ?? null)) {
                continue;
            }

            if ($alreadyBuiltIn !== null && $alreadyBuiltIn($locale)) {
                continue;
            }

            $translations[$locale] = $translator->translate($source, $sourceLanguage, $locale);
            $count++;
        }

        return $translations;
    }

    /**
     * Fills the empty languages of the given fields for each record and saves
     * what changed. For models with HasTranslations.
     *
     * @param  iterable<Model>  $records
     * @param  array<int, string>  $fields
     * @return int the number of new translations
     */
    public static function fillEmptyLanguages(iterable $records, array $fields): int
    {
        $count = 0;

        foreach ($records as $record) {
            $changed = false;

            foreach ($fields as $field) {
                $source = (string) $record->{$field};
                $before = $record->{"{$field}_translations"} ?? [];

                $after = static::fillIn(
                    $source,
                    $before,
                    $record->translateViaLanguageFile($field) ? fn (string $locale) => Lang::has(trim($source), $locale) : null,
                    $count,
                    $record->sourceLanguage(),
                );

                // Only assign if something was really added: otherwise an empty
                // column (null) becomes an empty list and counts as a change.
                if ($after !== $before) {
                    $record->{"{$field}_translations"} = $after;
                    $changed = true;
                }
            }

            if ($changed) {
                $record->save();
            }
        }

        return $count;
    }
}
