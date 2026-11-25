<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AdminLanguage;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Support\DatabaseUpdater;
use App\Support\InstallCode;
use App\Support\Languages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules;
use Throwable;

/**
 * Installation wizard for the first start: enter the database details,
 * create the tables and create an administrator — all through the browser, so
 * a buyer does not need SSH. The wizard closes itself as soon as an
 * account exists (see EnsureNotInstalled).
 */
class InstallController extends Controller
{
    /**
     * Lock in front of the wizard: the code is in a file on the server, so
     * only someone who can reach the files gets further. See InstallCode.
     */
    public function code()
    {
        InstallCode::ensureCode();

        return view('install.code', ['file' => InstallCode::FILE]);
    }

    public function verifyCode(Request $request)
    {
        $key = 'install_code:'.$request->ip();

        // Make guessing unattractive: 5 attempts per minute per IP.
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => __('Too many attempts. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ])]);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:200']]);

        if (! InstallCode::isCorrect($data['code'])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => __('This code is not correct.')]);
        }

        RateLimiter::clear($key);
        $request->session()->put('install_code_ok', true);

        return redirect()->route('install.database');
    }

    public function database(Request $request)
    {
        // Is the database already running with tables (for example because .env was
        // filled in by hand and migrate has already run)? Then step 1 is done
        // and you only need an account. With ?again=1 you can still change the
        // database details.
        if ($this->databaseReady() && ! $request->boolean('again')) {
            return redirect()->route('install.account');
        }

        $checks = $this->requirements();

        return view('install.database', [
            'checks' => $checks,
            'ready' => ! in_array(false, array_column($checks, 'ok'), true),
            'default' => $this->existingSettings(),
            'hasPassword' => $this->existingSettings()['password_set'],
        ]);
    }

    /**
     * Is there already a MySQL configuration in .env? Then use it as the
     * prefill, so you do not have to type anything twice — for example if you
     * filled in .env by hand earlier, or after a failed attempt.
     * We never show the password; we fill it in ourselves when saving.
     *
     * @return array{host: string, port: string, database: string, username: string, password_set: bool}
     */
    private function existingSettings(): array
    {
        $configured = config('database.default') === 'mysql';

        return [
            'host' => $configured ? (string) config('database.connections.mysql.host') : 'localhost',
            'port' => (string) (config('database.connections.mysql.port') ?: 3306),
            'database' => $configured ? (string) config('database.connections.mysql.database') : '',
            'username' => $configured ? (string) config('database.connections.mysql.username') : '',
            'password_set' => $configured && (string) config('database.connections.mysql.password') !== '',
        ];
    }

    public function storeDatabase(Request $request)
    {
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'app_url' => ['required', 'url', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::secureAddress((string) $value)) {
                    $fail(__('The address of the website must start with https://. Turn on the free SSL certificate at your hosting first.'));
                }
            }],
        ]);

        // Password field left empty while there is already one in .env? Then keep
        // that one — this way you do not have to retype an existing password (and
        // we do not show it anywhere in the form either).
        if (($data['db_password'] ?? '') === '' && $this->existingSettings()['password_set']) {
            $data['db_password'] = (string) config('database.connections.mysql.password');
        }

        try {
            $this->useConnection($data);
            DB::connection('mysql')->getPdo();
        } catch (Throwable $e) {
            return back()->withInput()->with('db_error', $e->getMessage());
        }

        // Build the tables first and only save the settings if that worked,
        // so a failed attempt never leaves a half-configured installation
        // behind.
        try {
            DatabaseUpdater::run();
        } catch (Throwable $e) {
            return back()->withInput()->with(
                'db_error',
                $e->getMessage()."\n\n".__('Empty the database and try again.')
            );
        }

        $this->writeEnv([
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            // Only warnings and errors, in a file per day kept for two weeks:
            // at debug level every mail (sign-in links, door codes, guest
            // details) would land in one log that is never cleaned up.
            'LOG_LEVEL' => 'warning',
            'LOG_STACK' => 'daily',
            'LOG_DAILY_DAYS' => '14',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['db_host'],
            'DB_PORT' => (string) $data['db_port'],
            'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'],
            'DB_PASSWORD' => $data['db_password'] ?? '',
        ]);

        return redirect()->route('install.account');
    }

    public function account()
    {
        if (! $this->databaseReady()) {
            return redirect()->route('install.database');
        }

        return view('install.account');
    }

    public function storeAccount(Request $request)
    {
        if (! $this->databaseReady()) {
            return redirect()->route('install.database');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // The first user is by definition the owner: they must be able to reach the
        // whole admin, so explicitly the administrator role.
        $user = User::create([
            ...$data,
            'role' => UserRole::Administrator,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        // Visitors and admins see their own browser language when it is on;
        // otherwise English, the source language. Both can be changed later
        // under Settings (Website → Languages and Admin).
        $this->makeDefault(Language::class, Languages::SOURCE);
        $this->makeDefault(AdminLanguage::class, Languages::SOURCE);

        // Without a company email address, contact forms and
        // conflict notifications arrive nowhere. The administrator's is the best guess;
        // it can be changed under Settings → Company.
        if (blank(Setting::get('company', 'email'))) {
            Setting::set('company', 'email', $user->email);
        }

        // Installation finished: the code no longer serves a purpose, and the wizard
        // closes itself from now on (EnsureNotInstalled).
        InstallCode::cleanUp();
        $request->session()->forget('install_code_ok');

        Auth::login($user);

        return redirect('/admin');
    }

    /**
     * Everything the server must be able to do before the application runs.
     *
     * @return array<int, array{label: string, ok: bool}>
     */
    private function requirements(): array
    {
        return [
            // 8.4.1: the lower bound of the packages (Symfony 8), see vendor/composer/platform_check.php.
            ['label' => __('PHP 8.4 or higher').' ('.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, '8.4.1', '>=')],
            ['label' => __('PHP extension :name', ['name' => 'pdo_mysql']), 'ok' => extension_loaded('pdo_mysql')],
            ['label' => __('PHP extension :name', ['name' => 'mbstring']), 'ok' => extension_loaded('mbstring')],
            ['label' => __('PHP extension :name', ['name' => 'openssl']), 'ok' => extension_loaded('openssl')],
            // Needed by the login of the AI connection (Passport); Composer refuses to install without it.
            ['label' => __('PHP extension :name', ['name' => 'sodium']), 'ok' => extension_loaded('sodium')],
            ['label' => __('PHP extension :name', ['name' => 'fileinfo']), 'ok' => extension_loaded('fileinfo')],
            ['label' => __('PHP extension :name', ['name' => 'gd']).' — '.__('for photos and PDFs'), 'ok' => extension_loaded('gd')],
            ['label' => __('PHP extension :name', ['name' => 'zip']).' — '.__('for layout templates and backups'), 'ok' => extension_loaded('zip')],
            ['label' => __('Folder :map writable', ['map' => 'storage/']), 'ok' => is_writable(storage_path())],
            ['label' => __('Folder :map writable', ['map' => 'bootstrap/cache/']), 'ok' => is_writable(base_path('bootstrap/cache'))],
            ['label' => __('Folder :map writable', ['map' => 'public/']), 'ok' => is_writable(public_path())],
            ['label' => __('File .env writable'), 'ok' => is_writable(base_path('.env')) || is_writable(base_path())],
        ];
    }

    private function databaseReady(): bool
    {
        try {
            return Schema::hasTable('users');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function useConnection(array $data): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $data['db_host'],
            'database.connections.mysql.port' => $data['db_port'],
            'database.connections.mysql.database' => $data['db_database'],
            'database.connections.mysql.username' => $data['db_username'],
            'database.connections.mysql.password' => $data['db_password'] ?? '',
        ]);

        DB::purge('mysql');
    }

    /**
     * Writes the values to .env; creates the file from .env.example
     * if it does not exist yet, and generates an APP_KEY if it is missing.
     *
     * @param  array<string, string>  $values
     */
    /**
     * A live website runs on https: otherwise sign-ins, payment links and
     * the session cross the network unencrypted. Plain http only for a site
     * on this computer (localhost, *.test, *.localhost).
     */
    /** The address of this visit, on https unless the site runs on this computer. */
    public static function suggestedAddress(): string
    {
        $url = rtrim(request()->root(), '/');

        return self::secureAddress($url) ? $url : (string) preg_replace('~^http://~i', 'https://', $url);
    }

    public static function secureAddress(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');

        if (! file_exists($path)) {
            copy(base_path('.env.example'), $path);
        }

        $contents = file_get_contents($path);

        if (! preg_match('/^APP_KEY=.+$/m', $contents)) {
            $values['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }

        file_put_contents($path, \App\Support\EnvFile::set($contents, $values));
    }

    /**
     * Make $code the default language of the website or the admin, when that
     * language exists. The other languages keep their on/off state.
     *
     * @param  class-string<Language>  $model
     */
    private function makeDefault(string $model, string $code): void
    {
        if (! $model::query()->where('code', $code)->exists()) {
            return;
        }

        $model::query()->update(['is_default' => false]);
        $model::query()->where('code', $code)->update(['is_default' => true, 'active' => true]);
        $model::forgets();
    }
}
