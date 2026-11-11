<?php

namespace App\Filament\Support;

use App\Models\Property;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Textarea;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/**
 * Two buttons for the editors of site texts (see RichEditors):
 *
 * - "From media library": opens the media library (the same picker as for the
 *   hero photos, pickMedia) and inserts the chosen photo with its alt text,
 *   a video as a video block (VideoBlock), or a link for another file type.
 *   Like the image button in devsite.
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
                // becomes an image, a video a video block, another file a link to it.
                ->jsHandler(strtr(<<<'JS'
                    window.pickMedia && window.pickMedia((file) => {
                        const extension = (file.url.split('?')[0].split('.').pop() || '').toLowerCase();
                        let content = { type: 'text', text: file.url.split('/').pop(), marks: [{ type: 'link', attrs: { href: file.url } }] };

                        if (/^(jpe?g|png|gif|webp|avif|svg)$/.test(extension)) {
                            content = { type: 'image', attrs: { src: file.url, alt: file.alt || null } };
                        } else if (__VIDEO_EXTENSIONS__.includes(extension)) {
                            const src = file.url.replace(/&/g, '&amp;').replace(/\x22/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            const preview = __VIDEO_PREVIEW__.replace('/__SRC__', src);
                            content = { type: 'customBlock', attrs: {
                                id: __VIDEO_ID__,
                                config: { src: file.url },
                                label: __VIDEO_LABEL__,
                                preview: btoa(String.fromCharCode(...new TextEncoder().encode(preview))),
                                shouldApplyProseStylingToPreview: false,
                            } };
                        }

                        $getEditor().chain().focus().insertContent(content).run();
                    })
                    JS, [
                    // The same preview as the server makes (VideoBlock), with the address filled in here.
                    // Js::from and no double quotes: Filament puts this script unescaped in an attribute.
                    '__VIDEO_EXTENSIONS__' => Js::from(Property::VIDEO_EXTENSIONS),
                    '__VIDEO_PREVIEW__' => Js::from(VideoBlock::toPreviewHtml(['src' => '/__SRC__'])),
                    '__VIDEO_ID__' => Js::from(VideoBlock::getId()),
                    '__VIDEO_LABEL__' => Js::from(VideoBlock::getLabel()),
                ])),
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
