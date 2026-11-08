<?php

namespace App\Support;

/**
 * Security for the installation wizard.
 *
 * As long as there is no user yet, /install is open — and a path like
 * /install is scanned by bots as a matter of course. Without a lock the first
 * visitor to come along could make themselves administrator.
 *
 * So on the first visit the wizard generates a random code and writes it to a
 * file on the server, outside the web root. Only someone who can reach the
 * files (SSH, FTP or the hosting file manager) can read it. This keeps
 * installing without SSH possible, but not for random visitors.
 */
class InstallCode
{
    /** Relative to the app folder; this is how it appears in the wizard and the README. */
    public const FILE = 'storage/app/install-code.txt';

    /** Outside public/, so never retrievable through a URL. */
    public static function path(): string
    {
        return base_path(self::FILE);
    }

    /** Creates the code if it does not exist yet, and returns it. */
    public static function ensureCode(): string
    {
        if (! is_file(self::path())) {
            if (! is_dir(dirname(self::path()))) {
                @mkdir(dirname(self::path()), 0775, true);
            }

            file_put_contents(self::path(), bin2hex(random_bytes(16))."\n");
        }

        return self::current();
    }

    public static function current(): string
    {
        return is_file(self::path()) ? trim((string) file_get_contents(self::path())) : '';
    }

    /** Compare in constant time, so the code cannot be guessed character by character. */
    public static function isCorrect(string $entered): bool
    {
        $code = self::current();

        return $code !== '' && hash_equals($code, trim($entered));
    }

    /** After a successful installation the code has no purpose any more. */
    public static function cleanUp(): void
    {
        if (is_file(self::path())) {
            @unlink(self::path());
        }
    }
}
