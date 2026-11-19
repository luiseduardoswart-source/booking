<?php

namespace App\Support;

/**
 * The very first start of an unpacked zip package: there is no .env yet, so
 * no APP_KEY either, and without a key Laravel cannot encrypt a cookie. Then
 * even the installation wizard crashes. So this creates the .env from
 * .env.example, with its own key and without error details, so a
 * visitor never sees technical error pages.
 *
 * Runs in bootstrap/app.php, before Laravel reads the .env. An existing
 * .env is always left alone.
 */
final class FirstStart
{
    public static function ensureEnv(string $base): void
    {
        $env = $base.DIRECTORY_SEPARATOR.'.env';

        // On every request after the first: only this one check.
        if (is_file($env)) {
            return;
        }

        $example = $base.DIRECTORY_SEPARATOR.'.env.example';

        if (! is_file($example) || ! is_writable($base)) {
            return;
        }

        // Not APP_ENV=production yet: CanonicalHost would then send every visitor
        // to the APP_URL from the example (localhost). The wizard sets the real
        // address and "production"; until then just hide error details.
        $content = self::set((string) file_get_contents($example), [
            'APP_KEY' => self::newKey(),
            'APP_DEBUG' => 'false',
        ]);

        // 'x': only create if it does not exist yet. If two first requests
        // arrive at the same time, the first wins and only one key remains.
        $file = @fopen($env, 'x');

        if ($file !== false) {
            fwrite($file, $content);
            fclose($file);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function set(string $content, array $values): string
    {
        return EnvFile::set($content, $values);
    }

    private static function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }
}
