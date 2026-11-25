<?php

namespace App\Listeners;

use App\Support\ErrorReports;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * Every logged error (a reported exception or Log::error) is also kept for
 * the owner's daily error email (see App\Support\ErrorReports).
 */
class RecordErrors
{
    public function handle(MessageLogged $event): void
    {
        $exception = $event->context['exception'] ?? null;

        ErrorReports::record($event->level, (string) $event->message, $exception instanceof Throwable ? $exception : null);
    }
}
