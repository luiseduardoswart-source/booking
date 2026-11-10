<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Two buttons for the editors of site texts (see RichEditors):
 *
 * - "From media library": opens the media library (the same picker as for the
 *   hero photos, pickMedia) and inserts the chosen photo with its alt text,
 *   or a link for another file type. Like the image button in devsite.
 * - "Source code": the HTML of the text in a window, to view or adjust, as
 *   in TinyMCE. The editor keeps only what it knows (no scripts or iframes).
 */
class SiteEditorPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    public function getTipTapJsExtensions(): array
    {
        return [];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('mediaLibrary')
                ->label(__('From media library'))
                ->icon(Heroicon::OutlinedPhoto)
                // pickMedia comes with the media assets (AppServiceProvider); a photo
                // becomes an image, another file a link to it.
                ->jsHandler(<<<'JS'
                    window.pickMedia && window.pickMedia((file) => {
                        const isImage = /\.(jpe?g|png|gif|webp|avif|svg)$/i.test(file.url);
                        $getEditor().chain().focus().insertContent(isImage
                            ? { type: 'image', attrs: { src: file.url, alt: file.alt || null } }
                            : { type: 'text', text: file.url.split('/').pop(), marks: [{ type: 'link', attrs: { href: file.url } }] }
                        ).run();
                    })
                    JS),
            RichEditorTool::make('sourceCode')
                ->label(__('Source code'))
                ->icon(Heroicon::OutlinedCodeBracket)
                ->action(arguments: '{ html: $getEditor().getHTML() }'),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make('sourceCode')
                ->modalHeading(__('Source code (HTML)'))
                ->modalDescription(__('Tags the editor does not support, such as scripts and iframes, are removed.'))
                ->modalWidth(Width::FiveExtraLarge)
                ->modalSubmitActionLabel(__('Apply'))
                ->fillForm(fn (array $arguments): array => ['html' => $arguments['html'] ?? ''])
                ->schema([
                    Textarea::make('html')
                        ->hiddenLabel()
                        ->rows(20)
                        ->extraInputAttributes(['style' => 'font-family: ui-monospace, monospace; font-size: 0.8rem;', 'spellcheck' => 'false']),
                ])
                // Replaces the content of the editor; the field follows on its own
                // (setContent sends an update), and is saved with Save as usual.
                ->action(fn (array $data, RichEditor $component) => $component->runCommands([
                    EditorCommand::make('setContent', arguments: [(string) ($data['html'] ?? '')]),
                ])),
        ];
    }
}
