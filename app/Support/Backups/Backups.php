<?php

namespace App\Support\Backups;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Backups of the site: one zip per backup with the database (database.sql)
 * and, unless switched off, the uploaded files (photos, videos, documents,
 * signatures). Made every night and with the button on the Backups page;
 * kept for a number of days (always at least the newest one).
 *
 * They are stored in storage/app/private/backups, outside the web folder:
 * only the owner downloads them, through BackupDownloadController.
 */
final class Backups
{
    public const DEFAULT_KEEP_DAYS = 7;

    public const MAX_KEEP_DAYS = 90;

    /** The only names a backup can have: nothing else is ever opened or deleted. */
    private const NAME = '/^backup-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/';

    /** Folders under storage/app/private that are no part of a backup. */
    private const SKIPPED_PRIVATE = ['backups', 'livewire-tmp'];

    /** Already compressed: stored as they are, which is much faster. */
    private const STORED_AS_IS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'mp4', 'webm', 'mov', 'zip', 'gz', 'pdf', 'docx', 'xlsx'];

    /**
     * Make a backup and remove the ones that are too old. A failure is kept
     * for the Backups page and passed on.
     */
    public static function run(): string
    {
        try {
            $name = self::make();
            self::prune();
            Setting::set('backups', 'last_failure', null);

            return $name;
        } catch (Throwable $exception) {
            Setting::set('backups', 'last_failure', now()->toIso8601String());
            Log::error('Backup failed: '.$exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }

    public static function make(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('A backup needs the PHP extension zip. Ask your hosting provider to switch it on.'));
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        File::ensureDirectoryExists(self::directory());

        $name = 'backup-'.now()->format('Y-m-d-His').'.zip';
        $target = self::directory().'/'.$name;
        $partial = $target.'.part';
        $sql = tempnam(self::directory(), 'database');

        try {
            DatabaseDump::writeTo($sql);

            $zip = new ZipArchive;

            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException(__('The backup could not be written.'));
            }

            $zip->addFile($sql, 'database.sql');
            $zip->addFromString('README.txt', self::readme());

            if (self::includesFiles()) {
                self::addFolder($zip, public_path('storage'), 'files/public');
                self::addFolder($zip, storage_path('app/private'), 'files/private', self::SKIPPED_PRIVATE);
            }

            if (! $zip->close()) {
                throw new RuntimeException(__('The backup could not be written.'));
            }

            rename($partial, $target);
        } finally {
            @unlink($sql);
            @unlink($partial);
        }

        activity()->event('backup_made')->withProperties(['backup' => $name])->log('backup_made');

        return $name;
    }

    /**
     * The backups, newest first, keyed by name.
     *
     * @return array<string, array{name: string, made_at: CarbonImmutable, size: int}>
     */
    public static function all(): array
    {
        if (! is_dir(self::directory())) {
            return [];
        }

        return collect(File::files(self::directory()))
            ->filter(fn (SplFileInfo $file): bool => preg_match(self::NAME, $file->getFilename()) === 1)
            ->map(fn (SplFileInfo $file): array => [
                'name' => $file->getFilename(),
                'made_at' => self::madeAt($file->getFilename()),
                'size' => (int) $file->getSize(),
            ])
            ->sortByDesc('made_at')
            ->keyBy('name')
            ->all();
    }

    /** The full path of an existing backup; null for any other name. */
    public static function path(string $name): ?string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        $path = self::directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);

        if ($path === null || ! unlink($path)) {
            return false;
        }

        activity()->event('backup_deleted')->withProperties(['backup' => $name])->log('backup_deleted');

        return true;
    }

    /** Remove backups older than the number of days to keep; the newest always stays. */
    public static function prune(): int
    {
        $limit = now()->subDays(self::keepDays());
        $removed = 0;

        foreach (array_slice(self::all(), 1) as $backup) {
            if ($backup['made_at']->lessThan($limit) && unlink(self::directory().'/'.$backup['name'])) {
                $removed++;
            }
        }

        return $removed;
    }

    public static function keepDays(): int
    {
        $days = (int) Setting::get('backups', 'keep_days', (string) self::DEFAULT_KEEP_DAYS);

        return max(1, min(self::MAX_KEEP_DAYS, $days));
    }

    public static function includesFiles(): bool
    {
        return Setting::get('backups', 'include_files', '1') === '1';
    }

    public static function lastFailure(): ?CarbonImmutable
    {
        $moment = Setting::get('backups', 'last_failure');

        return filled($moment) ? CarbonImmutable::parse($moment) : null;
    }

    public static function directory(): string
    {
        return storage_path('app/private/backups');
    }

    private static function madeAt(string $name): CarbonImmutable
    {
        preg_match(self::NAME, $name, $match);

        return CarbonImmutable::createFromFormat('Y-m-d-His', $match[1]);
    }

    /** @param array<int, string> $skipped folders directly under $folder */
    private static function addFolder(ZipArchive $zip, string $folder, string $prefix, array $skipped = []): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($folder) + 1));

            if (in_array(strtok($relative, '/'), $skipped, true)) {
                continue;
            }

            $entry = $prefix.'/'.$relative;
            $zip->addFile($file->getPathname(), $entry);

            if (in_array(strtolower($file->getExtension()), self::STORED_AS_IS, true)) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        }
    }

    private static function readme(): string
    {
        return <<<'TEXT'
            Backup of your booking site
            ===========================

            database.sql   The whole database. Import it into an empty database,
                           for example with phpMyAdmin (Import).
            files/public   Uploaded photos, videos and files: put them back in public/storage.
            files/private  Signatures and other private files: put them back in storage/app/private.

            Keep the file .env of your site (above all the line APP_KEY) as well:
            without that key, encrypted data (keys you entered, door codes) cannot
            be read in a restored site.
            TEXT;
    }
}
