<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guards the install wizard: once an account exists the installation is
 * done and the wizard closes itself. Without this, someone could open /install
 * later and overwrite the database credentials.
 */
class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isInstalled()) {
            return redirect('/admin');
        }

        return $next($request);
    }

    /**
     * Is the installation finished? That depends on one thing: whether a
     * user exists. Not on .env — that may already be filled in while nobody
     * can log in yet.
     */
    public static function isInstalled(): bool
    {
        try {
            // No Schema::hasTable(): that costs an extra information_schema
            // query. If the table does not exist, this simply throws an error.
            return User::query()->exists();
        } catch (Throwable $e) {
            // Only a missing table means "not installed yet".
            // On a database outage (server down, password changed) we do not
            // pretend the site must be set up again — the normal error page
            // should simply appear.
            return ! self::tableIsMissing($e);
        }
    }

    /**
     * Do the tables exist? This is different from isInstalled(): a site can
     * run fine while there is (temporarily) no user. Only if the tables are
     * missing has nothing really been set up yet.
     */
    public static function databaseIsSetUp(): bool
    {
        try {
            User::query()->exists();

            return true;
        } catch (Throwable $e) {
            return ! self::tableIsMissing($e);
        }
    }

    private static function tableIsMissing(Throwable $e): bool
    {
        // Freshly unpacked package: .env.example points to a SQLite file
        // that does not exist yet. That also means "not set up yet".
        // Laravel wraps that error in a QueryException.
        return $e instanceof SQLiteDatabaseDoesNotExistException
            || $e->getPrevious() instanceof SQLiteDatabaseDoesNotExistException
            || $e->getCode() === '42S02'                                    // MySQL/SQL Server
            || str_contains($e->getMessage(), 'no such table')              // SQLite
            || str_contains($e->getMessage(), 'Base table or view not found');
    }
}
