<?php

namespace App\Filament\Support;

use App\Filament\Pages\MediaManager;
use Filament\Forms\Components\RichEditor;

/**
 * The toolbar of the text editors for site texts (accommodation description,
 * pages). Only buttons whose result also shows on the site: no text colour,
 * that needs the admin's styles and stays invisible on the site.
 */
final class RichEditors
{
    /** Uploaded files ("Attach files") land in this folder of the media library. */
    public const ATTACHMENTS_DIRECTORY = 'media/content';

    public const SITE_TOOLBAR = [
        ['bold', 'italic', 'underline', 'strike', 'highlight', 'subscript', 'superscript', 'link'],
        ['h2', 'h3', 'lead', 'small'],
        ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
        ['bulletList', 'orderedList', 'blockquote', 'horizontalRule', 'details', 'codeBlock'],
        ['table', 'mediaLibrary', 'attachFiles'],
        ['clearFormatting', 'sourceCode'],
        ['undo', 'redo'],
    ];

    /**
     * An editor for a text on the site: the full toolbar, a photo from the
     * media library, the source code, and uploads into the media library. The
     * media library button only for who may use the media library.
     */
    public static function forSite(RichEditor $editor): RichEditor
    {
        $toolbar = MediaManager::canAccess()
            ? self::SITE_TOOLBAR
            : array_map(fn (array $group) => array_values(array_diff($group, ['mediaLibrary'])), self::SITE_TOOLBAR);

        return $editor
            ->plugins([SiteEditorPlugin::make()])
            ->toolbarButtons($toolbar)
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsDirectory(self::ATTACHMENTS_DIRECTORY)
            ->fileAttachmentsVisibility('public');
    }
}
