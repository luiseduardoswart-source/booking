<?php

namespace App\Engines;

use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Enums\IdentityStatus;
use App\Enums\MailType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Services\IdentityVerifier;
use App\Support\AdminNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The ID check after the payment, before arrival (accommodations that choose
 * it): a reminder some days before the deadline, and once the deadline has
 * passed either an automatic cancellation (with the chosen refund) or a
 * notification to the owner. Each booking is dealt with once.
 */
class IdentityDeadlineEngine
{
    public function __construct(
        private readonly NotificationEngine $notifications,
        private readonly BookingEngine $bookings,
        private readonly PaymentEngine $payments,
        private readonly IdentityVerifier $verifier,
    ) {}

    /** @return array{reminded: int, cancelled: int, notified: int} */
    public function run(): array
    {
        $done = ['reminded' => 0, 'cancelled' => 0, 'notified' => 0];
        $today = CarbonImmutable::today();

        foreach ($this->openChecks() as $booking) {
            $deadline = $booking->identityDeadline();
            $overdue = $today->greaterThan($deadline);
            $reminderDays = $booking->property->idReminderDays();
            $remind = ! $overdue && $reminderDays > 0 && $today->greaterThanOrEqualTo($deadline->subDays($reminderDays));

            // Something to do: first ask Stripe how the check stands.
            if (($overdue || $remind) && ! $this->stillUnverified($booking)) {
                continue;
            }

            if ($overdue) {
                $booking->property->idOverdueCancels() ? $this->cancel($booking) : $this->notify($booking);
                $done[$booking->property->idOverdueCancels() ? 'cancelled' : 'notified']++;
            } elseif ($remind) {
                $done['reminded'] += (int) $this->notifications->sendOnce($booking, MailType::IdentityReminder);
            }
        }

        return $done;
    }

    /**
     * A guest who verified and closed the page without coming back is only
     * known through the webhook; if that never arrived, Stripe still knows.
     * Without a started check there is nothing to ask. If Stripe cannot be
     * reached, the booking waits for the next day: better a day late than a
     * wrongful reminder or cancellation.
     */
    private function stillUnverified(Booking $booking): bool
    {
        if ($booking->identity_session_id === null) {
            return true;
        }

        try {
            $this->verifier->refresh($booking);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return $booking->refresh()->identity_status !== IdentityStatus::Verified;
    }

    /**
     * Confirmed bookings of accommodations with the check after the payment,
     * still unverified, arrival still to come, not dealt with yet.
     *
     * @return Collection<int, Booking>
     */
    private function openChecks(): Collection
    {
        $required = Property::valuePerAccommodation('id_required');
        $afterPayment = Property::valuePerAccommodation('id_moment')
            ->filter(fn ($moment, int $id) => $moment === Property::ID_AFTER_PAYMENT && (bool) $required[$id])
            ->keys();

        if ($afterPayment->isEmpty()) {
            return collect();
        }

        return Booking::query()
            ->with('property')
            ->whereIn('property_id', $afterPayment)
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Paid->value])
            ->where(fn ($query) => $query->whereNull('identity_status')->orWhere('identity_status', '!=', IdentityStatus::Verified->value))
            ->whereDate('arrival_date', '>=', CarbonImmutable::today())
            ->whereNull('identity_overdue_at')
            ->get();
    }

    private function notify(Booking $booking): void
    {
        $booking->update(['identity_overdue_at' => now()]);
        AdminNotifications::send(AdminNotification::IdentityMissing, $booking);
    }

    /**
     * Marked first, so the owner's cancellation notice says why; then the
     * refund the owner chose, and the guest is told.
     */
    private function cancel(Booking $booking): void
    {
        $booking->update(['identity_overdue_at' => now()]);
        $this->bookings->cancel($booking, __('Identity check not done by the deadline'));

        $refund = $booking->property->idCancelRefund();

        if ($refund !== Property::ID_REFUND_MANUAL) {
            $this->refund($booking, stayToo: $refund === Property::ID_REFUND_FULL);
        }

        $this->notifications->sendOnce($booking->refresh(), MailType::IdentityCancelled);
    }

    /**
     * The deposit always (it is not part of the price), the stay only when
     * everything goes back. A refund that fails at the provider is reported
     * and left to the owner, who has the cancellation notice.
     */
    private function refund(Booking $booking, bool $stayToo): void
    {
        $paid = $booking->payments()
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->where('type', '!=', PaymentType::Refund->value)
            ->get();

        foreach ($paid as $payment) {
            /** @var Payment $payment */
            try {
                if ($payment->depositHeldCents() > 0) {
                    $this->payments->refundDeposit($payment);
                }

                if ($stayToo && $payment->refresh()->stayRefundableCents() > 0) {
                    $this->payments->refund($payment);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
