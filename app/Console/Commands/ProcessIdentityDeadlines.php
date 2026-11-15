<?php

namespace App\Console\Commands;

use App\Engines\IdentityDeadlineEngine;
use Illuminate\Console\Command;

class ProcessIdentityDeadlines extends Command
{
    protected $signature = 'identity:process';

    protected $description = 'ID check after the payment: remind guests before the deadline, then cancel or notify the owner';

    public function handle(IdentityDeadlineEngine $deadlines): int
    {
        $done = $deadlines->run();

        $this->info("{$done['reminded']} reminder(s), {$done['cancelled']} cancelled, {$done['notified']} notification(s).");

        return self::SUCCESS;
    }
}
