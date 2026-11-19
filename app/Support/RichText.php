<?php

namespace App\Support;

use App\Filament\Support\VideoBlock;

/**
 * Text from the text editor. An emptied editor stores "<p></p>" (or a
 * paragraph with only a space); that is no text and must not count as
 * filled in, or it hides the text it should fall back to (the English
 * source, or the default's text).
 */
final class RichText
{
    /** The blocks of the site editors (RichEditors::forSite), by their id. */
    public const BLOCKS = [VideoBlock::class];

    /** Is there something to show: visible text, a merge field, an image or a video? */
    public static function filled(?string $html): bool
    {
        if ($html === null) {
            return false;
        }

        $visible = trim(html_entity_decode(strip_tags($html)), " \u{00A0}\t\n\r");

        return $visible !== ''
            || str_contains($html, 'data-type="mergeTag"')
            || str_contains($html, 'data-type="customBlock"')
            || str_contains($html, '<img');
    }

    /**
     * Text written outside the editor (the AI connection) as editor
     * HTML: Markdown for paragraphs, bold, lists and links. Any HTML in it is
     * dropped and only http(s) and mail links are kept, because the site
     * shows this text unescaped (render()).
     */
    public static function fromMarkdown(string $text): ?string
    {
        $html = trim((string) \Illuminate\Support\Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));

        // No pictures from elsewhere on the internet: photos come from the media library.
        $html = trim((string) preg_replace('#<img\b[^>]*>#i', '', $html));

        return self::filled($html) ? $html : null;
    }

    /** Does the text hold a photo or a block (such as a video), which plain text cannot keep? */
    public static function hasMedia(?string $html): bool
    {
        return $html !== null && (str_contains($html, '<img') || str_contains($html, 'data-type="customBlock"'));
    }

    /** Editor HTML as plain text with its paragraphs, for reading outside the admin. */
    public static function toPlainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6])>#i'], ["\n", "\n"], $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text)) ?: null;
    }

    /**
     * The text for the site: a block (such as a video) becomes its HTML, the
     * rest stays exactly as the editor stored it. Not Filament's
     * RichContentRenderer: that rebuilds the whole text and drops attached
     * photos and the "lead" style. An unknown or broken block disappears.
     */
    public static function render(?string $html): string
    {
        if ($html === null || ! str_contains($html, 'data-type="customBlock"')) {
            return (string) $html;
        }

        $blocks = collect(self::BLOCKS)->keyBy(fn (string $block): string => $block::getId());

        return (string) preg_replace_callback(
            '#<div\b[^>]*\bdata-type="customBlock"[^>]*>\s*</div>#i',
            function (array $match) use ($blocks): string {
                preg_match('#\bdata-id="([^"]*)"#', $match[0], $id);
                preg_match('#\bdata-config="([^"]*)"#', $match[0], $config);

                $block = $blocks->get(html_entity_decode($id[1] ?? ''));
                $config = json_decode(html_entity_decode($config[1] ?? '', ENT_QUOTES | ENT_HTML5), true);

                return $block !== null && is_array($config) ? (string) $block::toHtml($config, []) : '';
            },
            $html,
        );
    }
}
