<?php

namespace App\Console\Commands;

use App\Services\SmartLocks\SmartLockCodes;
use Illuminate\Console\Command;

class SyncLockCodes extends Command
{
    protected $signature = 'locks:sync';

    protected $description = 'Smart lock codes: takes those of stays that no longer get in off the lock, and gives stays that miss one their code with the check-in mail';

    public function handle(SmartLockCodes $codes): int
    {
        ['removed' => $removed, 'issued' => $issued] = $codes->sync();

        $this->info("{$removed} code(s) removed, {$issued} code(s) issued.");

        return self::SUCCESS;
    }
}
