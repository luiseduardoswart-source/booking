<?php

namespace App\Support;

/**
 * Where are the public files?
 *
 * Normally in public/ inside the project folder (locally, and for installation via
 * SSH with a symlink public_html → public). The zip package puts the app in its
 * own folder next to the web folder, so only the public files are reachable:
 *
 *     booking/       the app: .env, code, vendor/, storage/
 *     public_html/   the contents of public/
 *
 * See also public/index.php, which looks for the app folder from public_html.
 */
final class DirectoryLayout
{
    public const APP_DIR = 'booking';

    public const WEB_DIR = 'public_html';

    /** The web folder next to the app, or null for the normal layout with public/. */
    public static function publicDir(string $base): ?string
    {
        if (is_dir($base.DIRECTORY_SEPARATOR.'public')) {
            return null;
        }

        $webDir = dirname($base).DIRECTORY_SEPARATOR.self::WEB_DIR;

        return is_dir($webDir) ? $webDir : null;
    }
}
