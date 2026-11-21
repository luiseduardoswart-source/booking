<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A stay runs from calendar day to calendar day. A date can arrive as a
 * moment with a time zone ("2027-03-08T00:00:00+14:00"); compared as moments,
 * two such dates are fewer hours apart than the calendar days they name, and
 * a guest would pay fewer nights than the calendar keeps for them. Everything
 * that counts or prices nights therefore starts from the calendar day.
 */
final class CalendarDay
{
    /** The calendar day the date names (as written), at midnight in the app's time zone. */
    public static function of(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));
    }
}
