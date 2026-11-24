<?php

namespace App\Support;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\GuestLogin;
use App\Models\Review;
use App\Models\SentMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * A guest's right to be forgotten (GDPR): the personal data of all their
 * stays that are over or cancelled is erased. What the bookkeeping needs
 * stays: dates, amounts, payments and invoice numbers, under an anonymous
 * name. A coming stay keeps its data: the guest still needs it, and the
 * owner can erase it after the stay.
 *
 * Erased: name, email address, phone, address, notes, own form answers,
 * the owner's internal notes, the ID-check reference, the drawn signature
 * (the fingerprint of the signed text and the moment of signing stay), the
 * name on their reviews, the address on sent mails, their sign-in links
 * to My bookings, and the log lines about these bookings.
 */
final class GuestDataEraser
{
    /** The name an erased booking or review carries. */
    public const ANONYMOUS = 'Anonymous';

    /**
     * @return array{erased: int, kept: int} stays erased, and coming stays left as they are
     */
    public static function eraseGuestOf(Booking $booking): array
    {
        $email = mb_strtolower(trim((string) $booking->guest_email));

        $all = Booking::query()
            ->whereRaw('lower(guest_email) = ?', [$email])
            ->get();

        [$finished, $coming] = $all->partition(fn (Booking $stay) => $stay->status === BookingStatus::Cancelled
            || $stay->departure_date->isBefore(today()));

        DB::transaction(function () use ($finished, $email): void {
            foreach ($finished as $stay) {
                self::erase($stay);
            }

            if ($finished->isNotEmpty()) {
                GuestLogin::query()->whereRaw('lower(email) = ?', [$email])->delete();
            }
        });

        return ['erased' => $finished->count(), 'kept' => $coming->count()];
    }

    private static function erase(Booking $booking): void
    {
        $erasedAddress = "erased-{$booking->id}@invalid.invalid";

        if ($booking->signature_path !== null) {
            Storage::disk('local')->delete($booking->signature_path);
        }

        // Straight to the database: the booking's own log would otherwise
        // record the old name and address once more.
        Booking::query()->whereKey($booking->getKey())->update([
            'guest_name' => self::ANONYMOUS,
            'guest_email' => $erasedAddress,
            'guest_phone' => null,
            'guest_address' => null,
            'guest_postal_code' => null,
            'guest_city' => null,
            'notes' => null,
            'internal_notes' => null,
            'form_answers' => null,
            'identity_session_id' => null,
            'signature_name' => null,
            'signature_path' => null,
            'signature_ip' => null,
            'signature_user_agent' => null,
        ]);

        Review::query()->where('booking_id', $booking->id)->update(['guest_name' => self::ANONYMOUS]);
        SentMail::query()->where('booking_id', $booking->id)->update(['recipient' => $erasedAddress]);

        Activity::query()
            ->where('subject_type', $booking->getMorphClass())
            ->where('subject_id', $booking->getKey())
            ->delete();

        activity()->performedOn($booking)->event('guest_data_erased')->log('Guest data erased');
    }
}
