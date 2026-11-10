<?php

namespace App\Support;

/**
 * Text from the text editor. An emptied editor stores "<p></p>" (or a
 * paragraph with only a space); that is no text and must not count as
 * filled in, or it hides the text it should fall back to (the English
 * source, or the default's text).
 */
final class RichText
{
    /** Is there something to show: visible text, a merge field or an image? */
    public static function filled(?string $html): bool
    {
        if ($html === null) {
            return false;
        }

        $visible = trim(html_entity_decode(strip_tags($html)), " \u{00A0}\t\n\r");

        return $visible !== ''
            || str_contains($html, 'data-type="mergeTag"')
            || str_contains($html, '<img');
    }
}
