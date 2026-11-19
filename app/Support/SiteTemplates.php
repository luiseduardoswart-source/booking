<?php

namespace App\Support;

/**
 * Style templates for the website: a named bundle of style settings
 * (colours, header, font, button shape) that can be applied, exported and
 * imported in one go. The layout stays the same; only the look changes.
 * Values are stored as ordinary frontend settings, so fine-tuning per field
 * is still possible afterwards.
 */
class SiteTemplates
{
    /**
     * The style fields a template consists of (settings group 'frontend').
     */
    public const FIELDS = [
        'accent_color',
        'label_color',
        'background_color',
        'header_style',   // light | dark
        'font_style',     // default | serif | system
        'button_style',     // round | tight
    ];

    private const HEADER_STYLES = ['light', 'dark'];

    private const FONT_STYLES = ['default', 'serif', 'system'];

    private const BUTTON_STYLES = ['round', 'tight'];

    /**
     * The bundled templates. Key = technical name.
     *
     * @return array<string, array{name: string, description: string, tokens: array<string, ?string>}>
     */
    public static function all(): array
    {
        return [
            'airbnb' => [
                'name' => __('Airbnb style'),
                'description' => __('The familiar look: rose accent, light and friendly, rounded buttons.'),
                'tokens' => [
                    'accent_color' => '#e11d48',
                    'label_color' => null,
                    'background_color' => null,
                    'header_style' => 'light',
                    'font_style' => 'default',
                    'button_style' => 'round',
                ],
            ],
            'beach' => [
                'name' => __('Beach & sea'),
                'description' => __('Sea blue with a sandy background — airy, for coastal rentals.'),
                'tokens' => [
                    'accent_color' => '#0e7490',
                    'label_color' => '#155e75',
                    'background_color' => '#fbfaf6',
                    'header_style' => 'light',
                    'font_style' => 'default',
                    'button_style' => 'round',
                ],
            ],
            'luxury' => [
                'name' => __('Luxury & dark'),
                'description' => __('Dark header, golden-brown accent and serif headings — for villas and premium listings.'),
                'tokens' => [
                    'accent_color' => '#a16207',
                    'label_color' => '#1c1917',
                    'background_color' => '#faf9f7',
                    'header_style' => 'dark',
                    'font_style' => 'serif',
                    'button_style' => 'tight',
                ],
            ],
            'minimum' => [
                'name' => __('Minimal & modern'),
                'description' => __('Black and white with sharp corners and lots of whitespace — one restrained accent.'),
                'tokens' => [
                    'accent_color' => '#171717',
                    'label_color' => '#171717',
                    'background_color' => '#ffffff',
                    'header_style' => 'light',
                    'font_style' => 'system',
                    'button_style' => 'tight',
                ],
            ],
        ];
    }

    /**
     * Options list for the form: technical name => display name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::all())->map(fn (array $template) => $template['name'])->all();
    }

    /**
     * The fixed choices per style field; the colour fields take a colour
     * value (see isColor()).
     *
     * @return array<string, list<string>>
     */
    public static function choices(): array
    {
        return [
            'header_style' => self::HEADER_STYLES,
            'font_style' => self::FONT_STYLES,
            'button_style' => self::BUTTON_STYLES,
        ];
    }

    /**
     * Validates and cleans a set of tokens (from a preset or imported file):
     * colours must be real colour values and the style choices must exist.
     * Unknown keys and invalid values are silently dropped.
     *
     * @param  array<mixed>  $tokens
     * @return array<string, ?string>
     */
    public static function cleanTokens(array $tokens): array
    {
        $clean = [];

        foreach (self::FIELDS as $field) {
            $value = $tokens[$field] ?? null;
            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

            $valid = match ($field) {
                'accent_color', 'label_color', 'background_color' => $value === null || self::isColor($value),
                'header_style', 'font_style', 'button_style' => $value === null || in_array($value, self::choices()[$field], true),
            };

            $clean[$field] = $valid ? $value : null;
        }

        return $clean;
    }

    /**
     * Only real colour values may enter the stylesheet — this prevents an
     * imported template file from smuggling anything else in.
     */
    public static function isColor(?string $value): bool
    {
        return $value !== null && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]{1,30}\))$/', $value) === 1;
    }
}
