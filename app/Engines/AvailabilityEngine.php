<?php

namespace App\Engines;

use App\Engines\Availability\AvailabilityResult;
use App\Models\BookingRule;
use App\Models\Property;
use App\Support\CalendarDay;
use Carbon\CarbonImmutable;

/**
 * The single place in the system that decides whether an accommodation may be
 * booked on given dates. Website, admin, API, iCal import and the payment
 * flow all use this engine — no ad-hoc checks anywhere else.
 *
 * Semantics: a stay covers the nights from arrival up to and including the
 * night before departure. A calendar block covers the nights start_date to
 * end_date (inclusive). Departing on the first day of a block is therefore fine.
 */
class AvailabilityEngine
{
    public function check(
        Property $property,
        CarbonImmutable $arrival,
        CarbonImmutable $departure,
    ): AvailabilityResult {
        // Calendar days, not moments (see CalendarDay).
        $arrival = CalendarDay::of($arrival);
        $departure = CalendarDay::of($departure);

        if ($departure->lessThanOrEqualTo($arrival)) {
            return AvailabilityResult::no(__('The check-out date must be after the check-in date.'));
        }

        if (! $property->active) {
            return AvailabilityResult::no(__('This accommodation is currently not bookable.'));
        }

        $reasons = [];
        $nights = (int) $arrival->diffInDays($departure);
        $lastNight = $departure->subDay();

        // 1. Calendar blocks (manual, maintenance or externally synced)
        $hasBlock = $property->calendarBlocks()
            ->whereDate('start_date', '<=', $lastNight)
            ->whereDate('end_date', '>=', $arrival)
            ->exists();

        if ($hasBlock) {
            $reasons[] = __('This period is (partly) unavailable.');
        }

        // 2. Bookings — added once the Booking module exists (phase 5).
        //    BookingEngine::create() does the final re-check inside a
        //    database transaction with a row lock; this is the quick pre-check.
        if (class_exists(\App\Models\Booking::class)) {
            $hasBooking = \App\Models\Booking::query()
                ->where('property_id', $property->id)
                ->whereNotIn('status', \App\Models\Booking::INACTIVE_STATUSES)
                ->whereDate('arrival_date', '<', $departure)
                ->whereDate('departure_date', '>', $arrival)
                ->exists();

            if ($hasBooking) {
                $reasons[] = __('This period is already (partly) booked.');
            }
        }

        // 3. Minimum and maximum stay: hard limits, always in force (unlike
        //    the booking rules below, which only apply if a rule is set
        //    for this period).
        $minStayNights = $property->effective('min_stay_nights');

        if ($minStayNights !== null && $nights < $minStayNights) {
            $reasons[] = trans_choice('{1} Minimum stay is 1 night.|[2,*] Minimum stay is :count nights.', (int) $minStayNights);
        }

        $maxStayNights = $property->effective('max_stay_nights');

        if ($maxStayNights !== null && $nights > $maxStayNights) {
            $reasons[] = __('Maximum stay is :nights nights.', ['nights' => $maxStayNights]);
        }

        // 3b. Booking horizon: how far ahead bookings can be made.
        $windowMonths = $property->effective('booking_window_months');

        if ($windowMonths !== null && $arrival->greaterThan(CarbonImmutable::today()->addMonths((int) $windowMonths))) {
            $reasons[] = __('Booking that far ahead is not yet possible (maximum :months months).', ['months' => $windowMonths]);
        }

        // 4. Booking rules: own rules win; if the accommodation has none,
        //    the default rules of the master record apply.
        $rules = $property->bookingRules()->get();

        if ($rules->isEmpty() && ! $property->is_master) {
            $rules = Property::master()->bookingRules()->get();
        }

        $applicableRules = $rules->filter(fn (BookingRule $rule) => $this->ruleApplies($rule, $arrival));

        foreach ($applicableRules as $rule) {
            if ($nights < $rule->min_nights) {
                $reasons[] = $this->withRuleName($rule, __('Minimum stay is :nights nights.', ['nights' => $rule->min_nights]));
            }

            if ($rule->max_nights !== null && $nights > $rule->max_nights) {
                $reasons[] = $this->withRuleName($rule, __('Maximum stay is :nights nights.', ['nights' => $rule->max_nights]));
            }

            // Weekdays may be stored as strings (JSON from forms).
            $arrivalDays = array_map('intval', $rule->arrival_days ?? []);
            $departureDays = array_map('intval', $rule->departure_days ?? []);

            if ($arrivalDays !== [] && ! in_array($arrival->dayOfWeekIso, $arrivalDays, true)) {
                $reasons[] = $this->withRuleName($rule, __('Check-in is not possible on this day.'));
            }

            if ($departureDays !== [] && ! in_array($departure->dayOfWeekIso, $departureDays, true)) {
                $reasons[] = $this->withRuleName($rule, __('Check-out is not possible on this day.'));
            }
        }

        return $reasons === []
            ? AvailabilityResult::yes()
            : new AvailabilityResult(false, array_values(array_unique($reasons)));
    }

    /**
     * If the rule has a name, it is put before the message, in the guest's
     * language: "High season: Minimum stay is 7 nights." This way the guest
     * understands why this period is stricter. Without a name the message is unchanged.
     */
    private function withRuleName(BookingRule $rule, string $notification): string
    {
        $name = $rule->localized('name');

        return filled($name)
            ? __(':rule: :notification', ['rule' => $name, 'notification' => $notification])
            : $notification;
    }

    public function isAvailable(Property $property, CarbonImmutable $arrival, CarbonImmutable $departure): bool
    {
        return $this->check($property, $arrival, $departure)->available;
    }

    /**
     * All occupied nights in a window — for the availability calendar
     * on the website. Returns dates (Y-m-d) of nights that can no longer
     * be booked.
     *
     * @return string[]
     */
    public function unavailableDates(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        $blocks = $property->calendarBlocks()
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get();

        foreach ($blocks as $block) {
            $start = $block->start_date->greaterThan($from) ? $block->start_date : $from;
            $end = $block->end_date->lessThan($to) ? $block->end_date : $to;

            foreach (\Carbon\CarbonPeriod::create($start, $end) as $night) {
                $dates[$night->toDateString()] = true;
            }
        }

        $bookings = \App\Models\Booking::query()
            ->where('property_id', $property->id)
            ->whereNotIn('status', \App\Models\Booking::INACTIVE_STATUSES)
            ->whereDate('arrival_date', '<=', $to)
            ->whereDate('departure_date', '>', $from)
            ->get();

        foreach ($bookings as $booking) {
            $start = $booking->arrival_date->greaterThan($from) ? $booking->arrival_date : $from;
            $lastNight = $booking->departure_date->subDay();
            $end = $lastNight->lessThan($to) ? $lastNight : $to;

            foreach (\Carbon\CarbonPeriod::create($start, $end) as $night) {
                $dates[$night->toDateString()] = true;
            }
        }

        ksort($dates);

        return array_keys($dates);
    }

    /**
     * Minimum number of nights per arrival date, for the calendar on the site.
     * Combines the fixed lower limit with the period-bound booking rules;
     * only dates with a minimum above 1 are returned.
     *
     * @return array<string, int> date (Y-m-d) => minimum number of nights
     */
    public function minNightsMap(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = (int) ($property->effective('min_stay_nights') ?? 1);

        $rules = $property->bookingRules()->get();

        if ($rules->isEmpty() && ! $property->is_master) {
            $rules = Property::master()->bookingRules()->get();
        }

        $map = [];

        foreach (\Carbon\CarbonPeriod::create($from, $to) as $day) {
            $arrival = CarbonImmutable::instance($day);
            $min = $base;

            foreach ($rules as $rule) {
                if ($this->ruleApplies($rule, $arrival)) {
                    $min = max($min, (int) $rule->min_nights);
                }
            }

            if ($min > 1) {
                $map[$arrival->toDateString()] = $min;
            }
        }

        return $map;
    }

    private function ruleApplies(BookingRule $rule, CarbonImmutable $arrival): bool
    {
        if ($rule->start_date === null || $rule->end_date === null) {
            return true; // a rule without a period always applies
        }

        return $arrival->betweenIncluded($rule->start_date, $rule->end_date);
    }
}
