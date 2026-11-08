<?php

namespace App\Filament\Pages;

use App\Support\Access;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Media management (ported from devsite): folders and files under storage/media,
 * with uploading (drag & drop), renaming, alt texts and a photo editor.
 * The same browser also serves as a picker (pickMedia) for the
 * hero photos of Pages.
 */
class MediaManager extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.media-manager';

    public static function getNavigationLabel(): string
    {
        return __('Media');
    }

    public function getTitle(): string
    {
        return __('Media manager');
    }

    public static function canAccess(): bool
    {
        return Access::may(auth()->user(), 'media', Access::EDIT);
    }
}
