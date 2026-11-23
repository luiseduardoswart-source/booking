<?php

namespace App\Services\SmartLocks;

use App\Engines\NotificationEngine;
use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Models\Booking;
use App\Models\Property;
use App\Support\AdminNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A door code of its own per stay, from the accommodation's smart lock
 * (Property::ACCESS_SMART_LOCK). The code is made when the check-in mail goes
 * out, so only after the stay is paid in full, and opens the lock only from
 * check-in on the day of arrival until check-out on the day of departure
 * (local time of the accommodation). A stay that no longer gets in
 * (cancelled, payment returned) has its code taken off the lock.
 *
 * Every hour sync() puts right what did not work at once: a code the lock did
 * not give, a removal that failed, a stay that got in again.
 */
class SmartLockCodes
{
    /** How long the owner is not told again about the same stay. */
    private const TOLD_DAYS = 30;

    public function __construct(
        private readonly SeamLocks $locks,
        private readonly NotificationEngine $notifications,
    ) {}

    /**
     * Before the check-in mail: makes sure the stay has its code. True = the
     * mail may go now. Without a code the mail waits (sync() keeps trying
     * every hour) until the day of arrival: then it goes with "you will
     * receive the code separately" and the owner hears it. When the lock is
     * not set up at all (none picked, no Seam key), waiting does not help:
     * the owner hears it at once.
     */
    public function prepare(Booking $booking): bool
    {
        $property = $booking->property;

        if (! $property?->usesSmartLock() || filled($booking->lock_code)) {
            return true;
        }

        if (! $this->setUp($property)) {
            $this->tell(AdminNotification::SmartLockFailed, $booking);

            return true;
        }

        if ($this->obtain($booking)) {
            return true;
        }

        if ($booking->arrival_date->isAfter(today())) {
            return false;
        }

        $this->tell(AdminNotification::SmartLockFailed, $booking);

        return true;
    }

    /** Does the stay still get in? Confirmed, and not only partly paid. */
    public function entitled(Booking $booking): bool
    {
        return (bool) $booking->status?->isConfirmed() && ! $booking->owesBalance();
    }

    /**
     * Takes the stay's code off the lock. True when there is none (any more).
     * When it fails the owner hears it once; sync() keeps trying.
     */
    public function remove(Booking $booking): bool
    {
        if ($booking->lock_code_id === null) {
            return true;
        }

        if (! $this->locks->removeCode($booking->lock_code_id)) {
            $this->tell(AdminNotification::SmartLockNotRemoved, $booking);

            return false;
        }

        $booking->forceFill(['lock_code_id' => null, 'lock_code' => null])->saveQuietly();

        return true;
    }

    /**
     * Every hour (locks:sync):
     * 1. codes of coming stays that no longer get in come off the lock, also
     *    those that could not be removed at the cancellation itself;
     * 2. stays that should have a code and have none get one, with the
     *    check-in mail: a mail that waited for the lock goes now, and a mail
     *    that went without this code (the lock failed, the code was taken off
     *    and the guest got in again) goes once more.
     *
     * @return array{removed: int, issued: int}
     */
    public function sync(): array
    {
        $removed = 0;
        $issued = 0;

        Booking::query()
            ->whereNotNull('lock_code_id')
            ->whereDate('departure_date', '>=', today())
            ->lazyById()
            ->each(function (Booking $booking) use (&$removed): void {
                if (! $this->entitled($booking) && $this->remove($booking)) {
                    $removed++;
                }
            });

        if (! $this->locks->configured()) {
            return ['removed' => $removed, 'issued' => $issued];
        }

        $withLock = Property::query()->get()->filter(fn (Property $property) => $property->smartLockDevice() !== null)->modelKeys();

        Booking::query()
            ->with('property')
            ->whereIn('property_id', $withLock)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Paid->value])
            ->whereNull('lock_code')
            ->whereDate('departure_date', '>=', today())
            ->lazyById()
            ->each(function (Booking $booking) use (&$issued): void {
                if ($this->issue($booking)) {
                    $issued++;
                }
            });

        return ['removed' => $removed, 'issued' => $issued];
    }

    /**
     * From check-in on the day of arrival until check-out on the day of
     * departure, in the accommodation's time zone. Without a check-in time
     * from the start of the day of arrival, without a check-out time until
     * the end of the day of departure. Never starting in the past.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(Booking $booking): array
    {
        $property = $booking->property;
        $zone = $property->timeZone();
        $time = fn (string $field, string $otherwise): string => ($set = $property->effective($field)) ? Carbon::parse($set)->format('H:i') : $otherwise;

        $start = CarbonImmutable::parse($booking->arrival_date->toDateString().' '.$time('check_in_from', '00:00'), $zone);
        $end = CarbonImmutable::parse($booking->departure_date->toDateString().' '.$time('check_out_until', '23:59'), $zone);

        return [$start->max(CarbonImmutable::now($zone)), $end];
    }

    /**
     * A code for a stay that has none, sent with the check-in mail. Only for
     * a stay that gets in, and only once the check-in mail is due (or went
     * already, without this code).
     */
    private function issue(Booking $booking): bool
    {
        if (! $this->entitled($booking) || $booking->identityMissing()) {
            return false;
        }

        $mailed = $this->notifications->hasSent($booking, MailType::Checkin);
        $due = today()->addDays((int) $booking->property->effective('checkin_days_before_arrival'));

        if ((! $mailed && ($booking->arrival_date->isAfter($due) || $booking->arrival_date->isBefore(today()))) || ! $this->obtain($booking)) {
            return false;
        }

        // Once more with the new code; a mail that waited goes once.
        return $mailed
            ? $this->notifications->send($booking, MailType::Checkin)
            : $this->notifications->sendOnce($booking, MailType::Checkin);
    }

    private function setUp(Property $property): bool
    {
        return $property->smartLockDevice() !== null && $this->locks->configured();
    }

    /**
     * Makes the code, or fetches the digits the lock chose itself. A code
     * made earlier whose answer never arrived is taken over, never a second
     * one made beside it: that one would stay on the lock unknown.
     */
    private function obtain(Booking $booking): bool
    {
        $device = $booking->property?->smartLockDevice();

        if ($device === null) {
            return false;
        }

        if ($booking->lock_code_id === null) {
            [$start, $end] = $this->window($booking);
            $earlier = $end->isFuture() ? $this->locks->findCode($device, $booking->booking_number) : null;

            if ($earlier === null) {
                return false;
            }

            $made = $earlier['found']
                ? ['id' => $earlier['id'], 'code' => $earlier['code']]
                : $this->locks->createCode($device, $booking->booking_number, $start, $end);

            if ($made === null) {
                return false;
            }

            $booking->forceFill(['lock_code_id' => $made['id'], 'lock_code' => $made['code']])->saveQuietly();
        }

        if (blank($booking->lock_code)) {
            if (($code = $this->locks->code($booking->lock_code_id)) === null) {
                return false;
            }

            $booking->forceFill(['lock_code' => $code])->saveQuietly();
        }

        return true;
    }

    /** Tells the owner (and host) once per stay. */
    private function tell(AdminNotification $notification, Booking $booking): void
    {
        if (Cache::add("smart-lock.{$notification->value}.{$booking->id}", true, now()->addDays(self::TOLD_DAYS))) {
            AdminNotifications::send($notification, $booking);
        }
    }
}
