<?php

namespace App\Console\Commands;

use App\Services\MachineTranslator;
use App\Support\Languages;
use Illuminate\Console\Command;

/**
 * Generates or fills the language file of a new language (lang/xx.json) by
 * machine-translating all English texts. Existing translations
 * are kept; only missing keys are translated.
 *
 * Usage: php artisan lang:translate es
 */
class TranslateLanguage extends Command
{
    protected $signature = 'lang:translate {locale : The language code (e.g. es or zh)} {--force : Also re-translate existing keys}';

    protected $description = 'Machine-translates the full language file of a language from English (DeepL)';

    public function handle(MachineTranslator $translator): int
    {
        $locale = strtolower($this->argument('locale'));

        if ($locale === Languages::SOURCE) {
            $this->error('This is the source language: the keys in the code are English.');

            return self::FAILURE;
        }

        // The keys are the texts from the code, the values the English source.
        $sourcePath = lang_path('en.json');

        if (! file_exists($sourcePath)) {
            $this->error('lang/en.json not found.');

            return self::FAILURE;
        }

        $sourceTexts = json_decode(file_get_contents($sourcePath), true);
        $keys = array_keys($sourceTexts);

        $targetPath = lang_path("{$locale}.json");
        $existing = file_exists($targetPath) ? json_decode(file_get_contents($targetPath), true) : [];

        $translated = 0;
        $failed = 0;

        $this->withProgressBar($keys, function (string $key) use (&$existing, &$translated, &$failed, $sourceTexts, $translator, $locale) {
            if (! $this->option('force') && array_key_exists($key, $existing)) {
                return;
            }

            // Protect placeholders (:name) and plural separators from the translator.
            $shielded = preg_replace_callback('/:(\w+)/', fn ($m) => "[[{$m[1]}]]", $sourceTexts[$key]);
            $segments = explode('|', $shielded);

            try {
                $translatedSegments = array_map(
                    fn (string $segment) => $translator->translate($segment, Languages::SOURCE, $locale),
                    $segments,
                );
            } catch (\Throwable $e) {
                $failed++;

                return;
            }

            $result = implode('|', $translatedSegments);
            $result = preg_replace('/\[\[\s*(\w+)\s*\]\]/', ':$1', $result);

            $existing[$key] = $result;
            $translated++;
        });

        ksort($existing);
        file_put_contents(
            $targetPath,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $this->newLine(2);
        $this->info("{$translated} keys translated to lang/{$locale}.json".($failed > 0 ? " ({$failed} failed)" : '').'.');
        $this->comment('Check the translations and add the language under Settings, for the website or for the admin.');

        return self::SUCCESS;
    }
}
