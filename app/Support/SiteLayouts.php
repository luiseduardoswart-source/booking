<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Layout templates: complete themes that change the page layout of the site
 * (like themes in WordPress). A layout is a folder in
 * resources/views/templates/<name>/ containing the views it wants to
 * override (e.g. site/home.blade.php); anything the folder does not contain
 * automatically falls back to the default views. This way a purchased or
 * self-designed template can be installed as a folder and chosen here.
 */
class SiteLayouts
{
    public const DEFAULT = 'default';

    /**
     * All available layouts: the default plus every template folder.
     *
     * @return array<string, array{name: string, description: string}>
     */
    public static function all(): array
    {
        $layouts = [
            self::DEFAULT => [
                'name' => __('Default'),
                'description' => __('The built-in layout: classic homepage with search bar and card grid.'),
            ],
        ];

        foreach (glob(self::basePath().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $key = basename($dir);
            $meta = json_decode((string) @file_get_contents("{$dir}/template.json"), true) ?: [];

            $layouts[$key] = [
                'name' => is_string($meta['name'] ?? null) && $meta['name'] !== '' ? $meta['name'] : $key,
                'description' => is_string($meta['description'] ?? null) ? $meta['description'] : '',
            ];
        }

        return $layouts;
    }

    /**
     * Options for the form: technical name => display name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::all())->map(fn (array $layout) => $layout['name'])->all();
    }

    /**
     * The active layout. Unknown or deleted templates silently fall back
     * to the default, so the site never breaks.
     */
    public static function active(): string
    {
        $choice = Setting::get('frontend', 'layout', self::DEFAULT) ?: self::DEFAULT;

        if ($choice !== self::DEFAULT && ! is_dir(self::path($choice))) {
            return self::DEFAULT;
        }

        return $choice;
    }

    public static function path(string $name): string
    {
        // basename() prevents a settings value from pointing outside the template folder.
        return self::basePath().'/'.basename($name);
    }

    /**
     * Folder with public files (CSS, images) of a template.
     */
    public static function assetsPath(string $name): string
    {
        return public_path('templates/'.basename($name));
    }

    /**
     * The stylesheet a template ships with, or null. Templates ship their
     * CSS ready-made in the zip (assets/template.css) — so the administrator
     * never has to compile anything.
     */
    public static function stylesheetUrl(string $name): ?string
    {
        $path = self::assetsPath($name).'/template.css';

        return is_file($path) ? asset('templates/'.basename($name).'/template.css').'?v='.filemtime($path) : null;
    }

    /**
     * Installs an uploaded template file (.zip): views go into the
     * template folder, everything under assets/ is made public. Just like in
     * WordPress the system handles everything — uploading and choosing is enough.
     *
     * @return string the technical name of the installed template
     *
     * @throws \RuntimeException on an unreadable or invalid file
     */
    public static function installZip(string $zipPath): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('The file could not be read. Upload a .zip file.'));
        }

        try {
            // Look for template.json: in the root of the zip, or in a single subfolder
            // (like zips that contain a folder when you zip up a folder).
            $prefix = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));

                if (basename($entry) === 'template.json') {
                    $candidate = rtrim(dirname($entry), '/.');

                    if ($prefix === null || strlen($candidate) < strlen($prefix)) {
                        $prefix = $candidate;
                    }
                }
            }

            if ($prefix === null) {
                throw new \RuntimeException(__('This is not a valid layout template: the file template.json is missing from the zip.'));
            }

            $prefix = $prefix === '' ? '' : $prefix.'/';
            $name = \Illuminate\Support\Str::slug($prefix === '' ? pathinfo($zipPath, PATHINFO_FILENAME) : basename(rtrim($prefix, '/')));

            if ($name === '' || $name === self::DEFAULT) {
                $name = 'template-'.\Illuminate\Support\Str::random(6);
            }

            $viewsTarget = self::path($name);
            $assetsTarget = self::assetsPath($name);
            \Illuminate\Support\Facades\File::deleteDirectory($viewsTarget);
            \Illuminate\Support\Facades\File::deleteDirectory($assetsTarget);

            $allowed = ['blade.php', 'json', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'txt', 'md'];
            $placed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = str_replace('\\', '/', (string) $zip->getNameIndex($i));

                if (str_ends_with($entry, '/') || ($prefix !== '' && ! str_starts_with($entry, $prefix))) {
                    continue;
                }

                $relative = substr($entry, strlen($prefix));

                // Safety: no paths outside the target folder and only known file types.
                if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                    continue;
                }

                $extension = str_ends_with($relative, '.blade.php') ? 'blade.php' : strtolower(pathinfo($relative, PATHINFO_EXTENSION));

                if (! in_array($extension, $allowed, true)) {
                    continue;
                }

                $content = $zip->getFromIndex($i);

                if ($content === false) {
                    continue;
                }

                $target = str_starts_with($relative, 'assets/')
                    ? $assetsTarget.'/'.substr($relative, strlen('assets/'))
                    : $viewsTarget.'/'.$relative;

                \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($target));
                file_put_contents($target, $content);
                $placed++;
            }

            if ($placed === 0) {
                \Illuminate\Support\Facades\File::deleteDirectory($viewsTarget);
                \Illuminate\Support\Facades\File::deleteDirectory($assetsTarget);
                throw new \RuntimeException(__('The zip contained no usable template files.'));
            }

            return $name;
        } finally {
            $zip->close();
        }
    }

    private static function basePath(): string
    {
        return resource_path('views/templates');
    }
}
