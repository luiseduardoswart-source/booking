<?php

namespace App\Support;

use Closure;

/**
 * Remembers a value for the duration of one request, so something needed
 * dozens of times on a page (settings, languages) comes from the cache only
 * once. Lives in the container and so never longer than the request.
 */
final class RequestMemory
{
    /**
     * @template T
     *
     * @param  Closure(): T  $make
     * @return T
     */
    public static function remember(string $key, Closure $make): mixed
    {
        $name = self::name($key);

        if (! app()->bound($name)) {
            app()->instance($name, $make());
        }

        return app($name);
    }

    public static function forgets(string $key): void
    {
        app()->forgetInstance(self::name($key));
    }

    private static function name(string $key): string
    {
        return "request_memory.{$key}";
    }
}
