<?php

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Passwords and API keys in the settings (Setting::SECRETS) are stored
 * encrypted from now on: this encrypts the ones already stored as plain text.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(function (object $row): ?string {
            try {
                Crypt::decryptString($row->value);

                return null; // already encrypted
            } catch (DecryptException) {
                return Crypt::encryptString($row->value);
            }
        });
    }

    public function down(): void
    {
        $this->each(function (object $row): ?string {
            try {
                return Crypt::decryptString($row->value);
            } catch (DecryptException) {
                return null; // plain text already
            }
        });
    }

    /** @param  Closure(object): ?string  $convert  the new value, or null to leave the row */
    private function each(Closure $convert): void
    {
        foreach (Setting::SECRETS as $secret) {
            [$group, $key] = explode('.', $secret, 2);

            $row = DB::table('settings')->where('group', $group)->where('key', $key)->whereNotNull('value')->where('value', '!=', '')->first();

            if ($row !== null && ($value = $convert($row)) !== null) {
                DB::table('settings')->where('id', $row->id)->update(['value' => $value]);
            }
        }

        Setting::forgets();
    }
};
