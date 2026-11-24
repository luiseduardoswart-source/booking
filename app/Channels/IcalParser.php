<?php

namespace App\Channels;

use Carbon\CarbonImmutable;
use RuntimeException;

class IcalParser
{
    /**
     * Parses an iCal feed into booking periods. DTEND is exclusive in
     * iCal; we work with inclusive nights, so the last night is DTEND minus
     * one day.
     *
     * Only a real calendar counts: a page that is no calendar (a maintenance
     * or login page, an empty answer) would otherwise read as "no bookings"
     * and free every blocked night. An empty calendar is fine.
     *
     * @return array<int, array{external_uid: string, start_date: CarbonImmutable, end_date: CarbonImmutable, note: string|null}>
     *
     * @throws RuntimeException when the text is no calendar
     */
    public function parse(string $ics): array
    {
        if (! preg_match('/^\s*BEGIN:VCALENDAR\b/i', $ics) || ! preg_match('/END:VCALENDAR\s*$/i', $ics)) {
            throw new RuntimeException(__('The address did not return a calendar.'));
        }

        // Unfolding: lines starting with a space/tab continue the previous line.
        $ics = preg_replace("/\r\n[ \t]/", '', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $ics)));

        $entries = [];

        preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $events);

        foreach ($events[1] as $index => $event) {
            $uid = $this->value($event, 'UID') ?? 'without-uid-'.$index;
            $start = $this->date($event, 'DTSTART');
            $end = $this->date($event, 'DTEND');

            if ($start === null) {
                continue;
            }

            // No DTEND = single-day event; otherwise exclusive → inclusive.
            $lastNight = $end === null ? $start : $end->subDay();

            if ($lastNight->lessThan($start)) {
                $lastNight = $start;
            }

            $entries[] = [
                'external_uid' => $uid,
                'start_date' => $start,
                'end_date' => $lastNight,
                'note' => $this->value($event, 'SUMMARY'),
            ];
        }

        return $entries;
    }

    private function value(string $event, string $key): ?string
    {
        // Match "KEY:value" and "KEY;PARAM=x:value".
        if (preg_match('/^'.$key.'[;:]([^\r\n]*)/m', $event, $m)) {
            $raw = $m[1];
            $pos = strrpos($raw, ':');

            return trim($pos === false ? $raw : substr($raw, $pos + 1));
        }

        return null;
    }

    private function date(string $event, string $key): ?CarbonImmutable
    {
        $value = $this->value($event, $key);

        if ($value === null || ! preg_match('/^(\d{8})/', $value, $m)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd', $m[1])->startOfDay();
    }
}
