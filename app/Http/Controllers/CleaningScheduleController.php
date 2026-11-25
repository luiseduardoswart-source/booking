<?php

namespace App\Http\Controllers;

use App\Models\Cleaner;
use App\Support\CleaningSchedule;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The private page of a cleaner: the changeovers of their accommodations for
 * the coming month, always up to date, without an account. Only through the
 * signed link with the current version; a new link or switching the cleaner
 * off closes it at once.
 */
class CleaningScheduleController extends Controller
{
    public function __invoke(Request $request, Cleaner $cleaner, int $version): View
    {
        abort_unless($cleaner->active && $version === $cleaner->link_version, 404);

        app()->setLocale($cleaner->locale);

        $from = today()->toImmutable();

        return view('site.cleaning.schedule', [
            'cleaner' => $cleaner,
            'changeovers' => CleaningSchedule::changeovers($cleaner->properties()->orderBy('name')->get(), $from, $from->addDays(Cleaner::PAGE_DAYS)),
            'days' => Cleaner::PAGE_DAYS,
        ]);
    }
}
