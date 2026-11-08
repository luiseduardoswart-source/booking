<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Builds a prompt from the facts of an accommodation and turns the
 * AI answer into usable text fields (short description, description,
 * SEO title and description), in the requested language.
 */
class ListingCopywriter
{
    public function __construct(private AiTextGenerator $ai) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array{short_description: ?string, description: ?string, meta_title: ?string, meta_description: ?string}
     */
    public function write(array $facts, string $locale): array
    {
        $language = self::languageName($locale);

        $system = "You are an experienced copywriter for holiday homes and B&Bs. "
            ."You write persuasive, honest and concrete texts that convince guests to book, without exaggeration or empty clichés. "
            ."Write only in {$language}. "
            .'Answer ONLY with valid JSON, without explanation and without code fences.';

        $user = 'Write the persuasive texts for this accommodation based on these details:'."\n\n"
            .json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n"
            ."Return exactly this JSON object (all values in {$language}):\n"
            .'{'
            .'"short_description": "one catchy sentence of at most 150 characters", '
            .'"description": "2 to 4 short paragraphs, separated by an empty line; describe the accommodation, the location and the atmosphere", '
            .'"meta_title": "SEO title of at most 60 characters", '
            .'"meta_description": "SEO description of at most 155 characters"'
            .'}';

        $data = self::parseJson($this->ai->generate($system, $user));

        return [
            'short_description' => self::clean($data['short_description'] ?? null),
            'description' => self::clean($data['description'] ?? null),
            'meta_title' => self::clean($data['meta_title'] ?? null),
            'meta_description' => self::clean($data['meta_description'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseJson(string $answer): array
    {
        $clean = trim($answer);

        // Remove any ```json ... ``` code fences.
        if (str_starts_with($clean, '```')) {
            $clean = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $clean);
        }

        // Take the first { ... } block, in case there is text around it.
        if (preg_match('/\{.*\}/s', $clean, $match)) {
            $clean = $match[0];
        }

        $data = json_decode($clean, true);

        if (! is_array($data)) {
            throw new RuntimeException(__('The AI did not return a usable answer. Please try again.'));
        }

        return $data;
    }

    private static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function languageName(string $locale): string
    {
        if (extension_loaded('intl')) {
            return \Locale::getDisplayLanguage($locale, 'en') ?: $locale;
        }

        return [
            'nl' => 'Dutch', 'en' => 'English', 'de' => 'German',
            'fr' => 'French', 'es' => 'Spanish', 'zh' => 'Chinese',
        ][$locale] ?? $locale;
    }
}
