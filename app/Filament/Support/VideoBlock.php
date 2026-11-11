<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

/**
 * A video in a site text (accommodation description, page): a block in the
 * editor, a video player on the site. Inserted with "From media library"
 * (SiteEditorPlugin). The editor stores it as
 * <div data-type="customBlock" data-id="video" data-config='{"src":"…"}'>;
 * RichText::render() turns that into the player on the site.
 */
class VideoBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'video';
    }

    public static function getLabel(): string
    {
        return __('Video');
    }

    public static function getIcon(): Heroicon
    {
        return Heroicon::OutlinedFilm;
    }

    /** The address of the video can be adjusted with the block's edit button. */
    public static function configureEditorAction(Action $action): Action
    {
        return $action->schema([
            TextInput::make('src')
                ->label(__('Video address'))
                ->required()
                ->regex(self::SAFE_SRC),
        ]);
    }

    /** @param  array<string, mixed>  $config */
    public static function toPreviewHtml(array $config): ?string
    {
        return self::player($config, 'max-width:100%;max-height:20rem;border-radius:0.5rem');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return self::player($config, 'width:100%;height:auto;border-radius:0.75rem');
    }

    /** Only a web address or a path on this site, never javascript: or broken-off HTML. */
    public const SAFE_SRC = '#^(https?://|/)[^\s"\'<>]+$#i';

    /** @param  array<string, mixed>  $config */
    private static function player(array $config, string $style): ?string
    {
        $src = $config['src'] ?? null;

        if (! is_string($src) || ! preg_match(self::SAFE_SRC, $src)) {
            return null;
        }

        // #t=0.1: the browser shows the first frame as the still image.
        return '<video src="'.e($src).'#t=0.1" controls preload="metadata" playsinline style="'.$style.'"></video>';
    }
}
