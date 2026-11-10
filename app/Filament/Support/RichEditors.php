<?php

namespace App\Filament\Support;

use Filament\Forms\Components\RichEditor;

/**
 * The toolbar of the text editors for site texts (accommodation description,
 * pages). Only buttons whose result also shows on the site: no text colour,
 * that needs the admin's styles and stays invisible on the site.
 */
final class RichEditors
{
    public const SITE_TOOLBAR = [
        ['bold', 'italic', 'underline', 'strike', 'highlight', 'subscript', 'superscript', 'link'],
        ['h2', 'h3', 'lead', 'small'],
        ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
        ['bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'details', 'codeBlock'],
        ['table', 'attachFiles'],
        ['clearFormatting'],
        ['undo', 'redo'],
    ];

    /** An editor for a text on the site, with the full toolbar. */
    public static function forSite(RichEditor $editor): RichEditor
    {
        return $editor->toolbarButtons(self::SITE_TOOLBAR);
    }
}
