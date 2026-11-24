<?php

namespace App\Listeners;

use App\Models\Setting;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Throwable;

/**
 * Shrinks the stored original on upload — only if the administrator turned
 * that on in the settings. The site always serves the shrunken conversions
 * anyway; this purely saves storage on the originals.
 *
 * Registered by Laravel's event discovery (the type-hinted handle method).
 * Do not also register it with Event::listen, or every upload runs it twice.
 */
class OptimizeOriginalImage
{
    public function handle(MediaHasBeenAddedEvent $event): void
    {
        if (Setting::get('media', 'original_shrink', '0') !== '1') {
            return;
        }

        $media = $event->media;

        if (! in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }

        $path = $media->getPath();

        if (! is_file($path)) {
            return;
        }

        $max = max(600, (int) Setting::get('media', 'original_max_px', '2560'));

        $info = @getimagesize($path);

        if ($info === false) {
            return;
        }

        [$width, $height] = $info;

        if ($width <= $max && $height <= $max) {
            return; // already small enough
        }

        try {
            Image::load($path)
                ->fit(Fit::Max, $max, $max)
                ->quality(85)
                ->optimize()
                ->save();

            $media->size = filesize($path);
            $media->saveQuietly();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
