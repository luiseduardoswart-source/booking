<?php

namespace App\Console\Commands;

use App\Models\Cleaner;
use App\Support\CleaningSchedule;
use Illuminate\Console\Command;

class SendCleaningSchedules extends Command
{
    protected $signature = 'cleaning:send';

    protected $description = 'Emails every cleaner the changeovers of the coming days (daily, or on Mondays for weekly); no changeovers, no email';

    public function handle(): int
    {
        $sent = 0;

        Cleaner::query()->where('active', true)->each(function (Cleaner $cleaner) use (&$sent): void {
            if ($cleaner->mailDueToday() && CleaningSchedule::mail($cleaner)) {
                $sent++;
            }
        });

        $this->info("{$sent} cleaning schedule(s) sent.");

        return self::SUCCESS;
    }
}
