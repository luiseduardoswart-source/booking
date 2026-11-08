<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * The site's icon, in the formats browsers and phones ask for.
 * The source is the uploaded favicon, otherwise the logo. The formats are
 * generated from it automatically, square and with a transparent margin if
 * the image is not.
 */
class Favicon
{
    /** Pixels => file name: browser tab, iPhone home screen, Android. */
    public const FORMATS = [32 => 'favicon-32.png', 180 => 'apple-touch-icon.png', 192 => 'favicon-192.png'];

    private const DIR = 'branding/favicon';

    /** We do not open anything larger: a small upload could otherwise expand to gigabytes. */
    private const MAX_PIXELS = 25_000_000;

    /** Remembers a source that cannot be turned into a favicon, such as an SVG logo. */
    private const UNUSABLE = 'favicon.unusable';

    /** The file on the public disk the favicon is made from. */
    public static function source(): ?string
    {
        foreach (['favicon', 'logo'] as $key) {
            $path = Setting::get('branding', $key);

            if (filled($path) && Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Regenerates all formats, or cleans them up if there is no usable source.
     *
     * @return bool whether there is a favicon
     */
    public static function make(): bool
    {
        $disk = Storage::disk('public');
        $disk->deleteDirectory(self::DIR);

        $source = static::source();
        Cache::forget(self::UNUSABLE);
        $content = $source !== null ? $disk->get($source) : null;
        $sizes = $content !== null ? @getimagesizefromstring($content) : false;

        // Only raster images (no SVG) of a reasonable size.
        if ($sizes === false || $sizes[0] * $sizes[1] > self::MAX_PIXELS || ! ($image = @imagecreatefromstring($content))) {
            // Do not retry on every page; a new upload starts over.
            if ($source !== null) {
                Cache::forever(self::UNUSABLE, $source);
            }

            return false;
        }

        foreach (self::FORMATS as $size => $name) {
            $disk->put(self::DIR.'/'.$name, static::square($image, $size));
        }

        imagedestroy($image);

        return true;
    }

    /** The link tags for the head, with the file date appended to bust old caches. */
    public static function tags(): HtmlString
    {
        $disk = Storage::disk('public');
        $first = self::DIR.'/'.self::FORMATS[32];

        // If the logo existed before this feature, the formats do not exist yet.
        if (! $disk->exists($first) && (static::source() === null || Cache::get(self::UNUSABLE) === static::source() || ! static::make())) {
            return new HtmlString('');
        }

        $version = $disk->lastModified($first);
        $url = fn (int $size) => e($disk->url(self::DIR.'/'.self::FORMATS[$size]).'?v='.$version);

        return new HtmlString(implode("\n", [
            '<link rel="icon" type="image/png" sizes="32x32" href="'.$url(32).'">',
            '<link rel="icon" type="image/png" sizes="192x192" href="'.$url(192).'">',
            '<link rel="apple-touch-icon" sizes="180x180" href="'.$url(180).'">',
        ]));
    }

    /** The image fitted into a square, without cropping anything. */
    private static function square(\GdImage $image, int $size): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $size / max($width, $height);
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, intdiv($size - $newW, 2), intdiv($size - $newH, 2), 0, 0, $newW, $newH, $width, $height);

        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }
}
