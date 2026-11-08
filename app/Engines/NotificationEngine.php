<?php

namespace App\Engines;

use App\Enums\MailType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SentMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * All outgoing guest communication goes through here. Every mail is logged in
 * sent_mails; scheduled mails are sent at most once per booking per type
 * via sendOnce().
 */
class NotificationEngine
{
    /**
     * Returns false if the mail could not be queued.
     */
    public function send(Booking $booking, MailType $type, ?Payment $payment = null): bool
    {
        try {
            Mail::to($booking->guest_email)->queue(new BookingMail($booking, $type, $payment));

            SentMail::create([
                'booking_id' => $booking->id,
                'mail_type' => $type,
                'recipient' => $booking->guest_email,
                'status' => 'sent',
            ]);

            return true;
        } catch (Throwable $e) {
            SentMail::create([
                'booking_id' => $booking->id,
                'mail_type' => $type,
                'recipient' => $booking->guest_email,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            report($e);

            return false;
        }
    }

    /**
     * Only send if this mail has not been sent for this booking before.
     * Returns true if it was actually sent.
     */
    public function sendOnce(Booking $booking, MailType $type, ?Payment $payment = null): bool
    {
        if ($this->hasSent($booking, $type)) {
            return false;
        }

        $this->send($booking, $type, $payment);

        return true;
    }

    public function hasSent(Booking $booking, MailType $type): bool
    {
        return SentMail::query()
            ->where('booking_id', $booking->id)
            ->where('mail_type', $type->value)
            ->where('status', 'sent')
            ->exists();
    }
}
