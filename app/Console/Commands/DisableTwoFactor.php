<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emergency exit for someone who lost both their phone and their recovery
 * codes: turns two-factor authentication off for that account. Can only be run
 * on the server; if 2FA is mandatory, the user has to set it up again at the
 * next login.
 */
class DisableTwoFactor extends Command
{
    protected $signature = 'admin:disable-two-factor {email : Email address of the account}';

    protected $description = 'Turn off two-factor authentication for one account (for a lost phone and recovery codes)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('There is no account with this email address.');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactor()) {
            $this->info('Two-factor authentication was already off for this account.');

            return self::SUCCESS;
        }

        $user->disableTwoFactor();
        $this->info('Two-factor authentication has been turned off. Have the user set it up again after logging in.');

        return self::SUCCESS;
    }
}
