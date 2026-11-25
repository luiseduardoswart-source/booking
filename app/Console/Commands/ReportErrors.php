<?php

namespace App\Console\Commands;

use App\Support\ErrorReports;
use Illuminate\Console\Command;

class ReportErrors extends Command
{
    protected $signature = 'errors:report';

    protected $description = 'Emails the owner what went wrong since the previous email (errors, failed emails, calendars, backup); nothing new, no email';

    public function handle(): int
    {
        $this->info(ErrorReports::sendDigest() ? 'Error email sent.' : 'Nothing went wrong: no email.');

        return self::SUCCESS;
    }
}
