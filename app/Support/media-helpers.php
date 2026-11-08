<?php

// Media helpers (ported from devsite): URL and alt text of files from the
// media manager (public disk, media/ folder).

if (! function_exists('media_url')) {
    /** URL of a media file; absolute URLs and paths stay as they are. */
    function media_url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }
}

if (! function_exists('media_alt')) {
    /**
     * Alt text of an image: manually set in the media manager wins, then the
     * given fallback, and otherwise the file name — so every image always has
     * an alt.
     */
    function media_alt(?string $path, ?string $fallback = null): string
    {
        $external = ! $path || str_starts_with($path, 'http://') || str_starts_with($path, 'https://');

        // manually set in the media manager always wins
        if (! $external && ($manual = \App\Models\MediaAlt::stored($path)) !== null) {
            return $manual;
        }

        $fallback = trim((string) $fallback);
        if ($fallback !== '') {
            return $fallback;
        }

        return $external ? '' : \App\Models\MediaAlt::fromFilename($path);
    }
}
