<?php

namespace App\Support;

/**
 * Writing values into the .env file (installation wizard, first start). A
 * value is written so the .env reader gets exactly that value back, whatever
 * characters a password contains: quotes, a backslash, $1 or a #.
 */
final class EnvFile
{
    /**
     * Sets keys in the contents of a .env file: an existing line is
     * replaced, a new key is added at the end.
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public static function set(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = self::line($key, $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            // A callback, not a replacement string: "$1" or "\1" in a
            // password must not be read as a back-reference.
            $contents = preg_match($pattern, $contents)
                ? (string) preg_replace_callback($pattern, fn () => $line, $contents, 1)
                : rtrim($contents)."\n".$line."\n";
        }

        return $contents;
    }

    public static function line(string $key, string|int|bool|null $value): string
    {
        // One line per key: a line break would start a new (made-up) key.
        $value = str_replace(["\r", "\n"], '', (string) $value);

        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+,=-]+$/', $value) === 1) {
            return $key.'='.$value;
        }

        // Single quotes keep everything literally ($ included); only a value
        // with a single quote in it needs double quotes with escapes.
        if (! str_contains($value, "'")) {
            return $key."='".$value."'";
        }

        return $key.'="'.addcslashes($value, '\\"$').'"';
    }
}
