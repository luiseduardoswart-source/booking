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
