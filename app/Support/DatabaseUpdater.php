<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;

/**
 * After updating via the zip package, new migrations are waiting, but
 * without SSH nobody can run `php artisan migrate`. The dashboard then shows
 * a button that does it here in the same PHP process (like the wizard), because
 * on shared hosting no separate process can be started.
 */
final class DatabaseUpdater
{
    private const SECONDS = 300;

    /** How many migrations have not been run yet. */
    public static function outstanding(): int
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return 0;
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    /** Also used by the installation wizard. */
    public static function run(): void
    {
        self::raiseTimeLimit();
        // Must not stop halfway if the admin closes the tab.
        @ignore_user_abort(true);

        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Migrations take longer than a normal request: raise the limit to
     * 300 seconds. Never lower it: on the command line (artisan, the tests) there is
     * no limit (0), and set_time_limit(300) would introduce one.
     */
    private static function raiseTimeLimit(): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && $limit < self::SECONDS) {
            @set_time_limit(self::SECONDS);
        }
    }
}
