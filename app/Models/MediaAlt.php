<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Manually set alt text for a media file. If there is no row, media_alt()
 * derives the alt from the file name — so every image always has an alt.
 */
class MediaAlt extends Model
{
    protected $fillable = ['path', 'alt'];

    /** All manual alt texts, cached per request (path => alt). */
    protected static ?array $cache = null;

    protected static function allAlts(): array
    {
        if (static::$cache === null) {
            try {
                static::$cache = static::pluck('alt', 'path')->all();
            } catch (\Throwable) {
                static::$cache = [];   // table does not exist yet (installation)
            }
        }

        return static::$cache;
    }

    /** Alt text derived from the file name: "mooi_huis-01.jpg" → "Mooi huis 01". */
    public static function fromFilename(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/[_\-\.\+]+/', ' ', $name);   // separators → space
        $name = preg_replace('/\s+/', ' ', (string) $name);

        return Str::ucfirst(trim((string) $name));
    }

    /** Only the manually set alt (null if there is none). */
    public static function stored(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $alt = static::allAlts()[$path] ?? null;

        return $alt !== null && $alt !== '' ? $alt : null;
    }

    /** The alt text of a file: manually set, otherwise from the file name. */
    public static function forPath(string $path): string
    {
        return static::stored($path) ?? static::fromFilename($path);
    }

    /** Stores a manual alt; an empty value resets it to the file name. */
    public static function put(string $path, ?string $alt): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $alt = trim((string) $alt);

        if ($alt === '') {
            static::where('path', $path)->delete();
            unset(static::$cache[$path]);

            return;
        }

        static::updateOrCreate(['path' => $path], ['alt' => $alt]);
        static::$cache[$path] = $alt;
    }

    /** Moves the alt along when a file is renamed or moved. */
    public static function move(string $from, string $to): void
    {
        $from = ltrim(str_replace('\\', '/', $from), '/');
        $to = ltrim(str_replace('\\', '/', $to), '/');
        static::where('path', $from)->update(['path' => $to]);
        static::$cache = null;
    }

    /** Cleans up the alt when a file or folder is deleted. */
    public static function forget(string $path): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        static::where('path', $path)->orWhere('path', 'like', $path . '/%')->delete();
        static::$cache = null;
    }
}
