<?php

namespace App\Support;

/**
 * Clears caches once after an update with the zip package.
 *
 * The zip contains a VERSION file (see .github/workflows/release.yml).
 * Extracted files often keep the date from the archive, which can be older
 * than the compiled views on the server; Laravel then keeps showing old
 * pages. The package list and other files in bootstrap/cache may no longer
 * match the new code either. So when VERSION differs from last time, those
 * caches are cleared once; Laravel rebuilds them by itself. Without VERSION
 * (locally, or an install with git, where deploy.sh does this) nothing happens.
 *
 * Taken over from the cms project (App\Support\Release there), with the
 * Filament caches in bootstrap/cache/filament included.
 */
final class Release
{
    public static function versionFile(): string
    {
        return base_path('VERSION');
    }

    public static function markerFile(): string
    {
        return storage_path('framework/deployed-version');
    }

    /** True when there was a new version and the caches were cleared. */
    public static function refreshIfNew(?string $versionFile = null, ?string $markerFile = null): bool
    {
        $versionFile ??= self::versionFile();
        $markerFile ??= self::markerFile();

        if (! is_file($versionFile)) {
            return false;
        }

        $version = trim((string) file_get_contents($versionFile));
        $deployed = is_file($markerFile) ? trim((string) file_get_contents($markerFile)) : null;

        if ($version === '' || $version === $deployed) {
            return false;
        }

        self::clearCaches();
        @file_put_contents($markerFile, $version);

        return true;
    }

    /** Compiled views and every cache file under bootstrap/cache (also Filament's, in a subfolder). */
    private static function clearCaches(): void
    {
        foreach (glob(rtrim((string) config('view.compiled'), '/\\').'/*.php') ?: [] as $file) {
            @unlink($file);
        }

        $bootstrapCache = app()->bootstrapPath('cache');

        if (! is_dir($bootstrapCache)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bootstrapCache, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                @unlink($file->getPathname());
            }
        }
    }
}
