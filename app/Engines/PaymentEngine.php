<?php

namespace App\Engines;

use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * All payment logic in one place. The engine talks to the provider via the
 * PaymentProvider interface and updates the payment and booking status
 * after every status change.
 */
class PaymentEngine
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly BookingEngine $bookings,
        private readonly NotificationEngine $notifications,
    ) {}

    /**
     * Create a payment request (down payment, balance or deposit) and return the
     * Payment with checkout URL. If a valid link already exists for
     * the same amount, that one is returned: two links side by side means
     * the guest can pay twice. Therefore the other open
     * links for the same purpose expire too.
     */
    public function createPayment(Booking $booking, PaymentType $type): Payment
    {
        if ($booking->status === BookingStatus::Cancelled) {
            throw new InvalidArgumentException(__('This booking has been cancelled.'));
        }

        // Deposit right after booking: it goes along with the first payment of
        // the stay, in one checkout. Its own open links then expire too, so the
        // guest cannot pay the deposit twice.
        $withDeposit = $type === PaymentType::DownPayment && $booking->property?->depositAtBooking() === true;

        // Ask the provider first: an open link may have been paid or
        // expired by now, and that determines what is left to pay.
        $open = $this->validPayments($booking, [...$this->sameTarget($type), ...($withDeposit ? [PaymentType::Deposit] : [])]);

        $amountCents = match ($type) {
            PaymentType::DownPayment => $this->downPaymentCents($booking),
            PaymentType::Balance => $this->remainingAmountCents($booking),
            PaymentType::Deposit => $this->depositCents($booking),
            PaymentType::Refund => throw new InvalidArgumentException('Use refund() for refunds.'),
        };

        if ($amountCents <= 0) {
            throw new InvalidArgumentException(__('There is nothing to pay for this type.'));
        }

        $depositPartCents = $withDeposit ? $this->depositCents($booking) : 0;
        $amountCents += $depositPartCents;

        $existing = $open->first(
            fn (Payment $payment): bool => $payment->type === $type
                && $payment->amount_cents === $amountCents
                && (int) $payment->deposit_part_cents === $depositPartCents
        );

        $open->reject(fn (Payment $payment): bool => $payment->is($existing))
            ->each(fn (Payment $payment) => $this->letsExpire($payment));

        if ($existing !== null) {
            return $existing;
        }

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'type' => $type,
            'status' => PaymentTransactionStatus::Open,
            'amount_cents' => $amountCents,
            'deposit_part_cents' => $depositPartCents,
            'provider' => $this->provider->name(),
            'description' => $depositPartCents > 0
                ? "{$booking->paymentKind($type)} + ".PaymentType::Deposit->label()." {$booking->booking_number}"
                : "{$booking->paymentKind($type)} {$booking->booking_number}",
        ]);

        $result = $this->provider->create(
            $payment,
            redirectUrl: $payment->returnUrl(),
        );

        $payment->update([
            'provider_payment_id' => $result->providerPaymentId,
            'checkout_url' => $result->checkoutUrl,
        ]);

        return $payment;
    }

    /**
     * The open links of this provider that, according to the provider, can still
     * be paid. If a link is unknown there (e.g. after changing a key),
     * it does not count: that must not stand in the way of a new link.
     *
     * @param  PaymentType[]  $types
     * @return Collection<int, Payment>
     */
    private function validPayments(Booking $booking, array $types): Collection
    {
        return $booking->openPayments()
            ->whereIn('type', $types)
            ->where('provider', $this->provider->name())
            ->filter(function (Payment $payment): bool {
                try {
                    return $this->syncFromProvider($payment)->status === PaymentTransactionStatus::Open;
                } catch (Throwable $e) {
                    report($e);

                    return false;
                }
            });
    }

    /**
     * Down payment and balance pay for the same stay; the deposit is separate.
     *
     * @return PaymentType[]
     */
    private function sameTarget(PaymentType $type): array
    {
        return $type === PaymentType::Deposit
            ? [PaymentType::Deposit]
            : [PaymentType::DownPayment, PaymentType::Balance];
    }

    /**
     * Let all open links of a booking expire, e.g. after cancellation.
     */
    public function letsOpenPaymentsExpire(Booking $booking): void
    {
        $this->validPayments($booking, PaymentType::cases())
            ->each(fn (Payment $payment) => $this->letsExpire($payment));
    }

    /**
     * If expiring fails, the payment stays open: the link can then still
     * be paid. That must not stop the current action.
     */
    private function letsExpire(Payment $payment): void
    {
        try {
            $this->provider->cancel($payment);
            $payment->update(['status' => PaymentTransactionStatus::Cancelled]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Create the down payment link and return the checkout URL. If that fails
     * (e.g. provider not configured yet), the payment link follows by
     * mail and the guest goes to the confirmation page for now.
     */
    public function checkoutOrConfirmationUrl(Booking $booking): string
    {
        try {
            return $this->createPayment($booking, PaymentType::DownPayment)->checkout_url;
        } catch (Throwable $e) {
            // Nothing (more) to pay is not an error.
            if (! $e instanceof InvalidArgumentException) {
                report($e);
            }

            return URL::signedRoute('bookings.confirmation', $booking);
        }
    }

    /**
     * Fetch the current status from the provider and process it. Idempotent:
     * webhooks may arrive twice without being processed twice.
     * Used by both the webhook and the return page.
     */
    public function syncFromProvider(Payment $payment): Payment
    {
        $providerStatus = $this->provider->status($payment);

        $newStatus = match ($providerStatus) {
            ProviderPaymentStatus::Paid => PaymentTransactionStatus::Paid,
            ProviderPaymentStatus::Failed => PaymentTransactionStatus::Failed,
            ProviderPaymentStatus::Expired => PaymentTransactionStatus::Expired,
            ProviderPaymentStatus::Canceled => PaymentTransactionStatus::Cancelled,
            ProviderPaymentStatus::Open => PaymentTransactionStatus::Open,
        };

        if ($payment->status === $newStatus) {
            return $payment; // nothing changed (e.g. duplicate webhook)
        }

        // Extra check: only counts as paid if the provider reports the same
        // amount in the same currency as we created. If not,
        // the payment stays as is and the admin has to look.
        if ($newStatus === PaymentTransactionStatus::Paid && ! $this->provider->amountIsCorrect($payment)) {
            Log::critical('Paid amount differs from the payment; not processed as paid', [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'provider' => $this->provider->name(),
            ]);

            return $payment;
        }

        $payment->status = $newStatus;

        if ($newStatus === PaymentTransactionStatus::Paid && $payment->paid_at === null) {
            $payment->paid_at = now();
        }

        $payment->save();

        if ($newStatus === PaymentTransactionStatus::Paid) {
            $this->applyPaymentToBooking($payment->booking);
            $booking = $payment->booking->refresh();
            $this->notifications->send($booking, MailType::PaymentReceived, $payment);

            // Deposit right after booking: the request goes out as soon as the stay
            // is paid for (the payment page shows the button as well).
            if ($payment->type !== PaymentType::Deposit && $this->depositDueNow($booking)) {
                $this->notifications->sendOnce($booking, MailType::Deposit);
            }
        }

        return $payment;
    }

    /**
     * Refund the deposit a payment holds: a deposit payment, or the deposit
     * part of a first payment that included it.
     */
    public function refundDeposit(Payment $payment): Payment
    {
        return $this->refund($payment, null, deposit: true);
    }

    /**
     * Refund (part of) a paid payment. Without $deposit only the stay part: a
     * deposit paid along with the stay has its own refund (refundDeposit), so
     * it is never refunded as if it were the stay, or the other way round.
     */
    public function refund(Payment $payment, ?int $amountCents = null, bool $deposit = false): Payment
    {
        if ($payment->status !== PaymentTransactionStatus::Paid) {
            throw new InvalidArgumentException(__('Only paid payments can be refunded.'));
        }

        $deposit = $deposit || $payment->type === PaymentType::Deposit;
        $maximum = $deposit ? $payment->depositHeldCents() : $payment->stayRefundableCents();

        if ($maximum <= 0) {
            throw new InvalidArgumentException(__('This payment has already been fully refunded.'));
        }

        $amountCents ??= $maximum;

        if ($amountCents <= 0 || $amountCents > $maximum) {
            throw new InvalidArgumentException(__('At most :amount of this payment can still be refunded.', ['amount' => Money::format($maximum, $payment->booking?->currency)]));
        }

        $refundId = $this->provider->refund($payment, $amountCents);

        $refund = Payment::create([
            'booking_id' => $payment->booking_id,
            'type' => PaymentType::Refund,
            'status' => PaymentTransactionStatus::Refunded,
            'amount_cents' => $amountCents,
            'deposit_part_cents' => $deposit ? $amountCents : 0,
            'provider' => $this->provider->name(),
            'provider_payment_id' => $refundId,
            'refunded_payment_id' => $payment->id,
            'description' => "Refund of {$payment->description}",
            'paid_at' => now(),
        ]);

        $this->applyPaymentToBooking($payment->booking);

        return $refund;
    }

    /**
     * Amount still outstanding: total minus everything already paid
     * (the deposit does not count towards the total).
     */
    public function remainingAmountCents(Booking $booking): int
    {
        return $booking->paymentOverview()['outstanding'];
    }

    /**
     * The down payment, as long as nothing has been paid that has not been refunded.
     */
    private function downPaymentCents(Booking $booking): int
    {
        return $this->paidAmountCents($booking) > 0 ? 0 : $booking->down_payment_cents;
    }

    /**
     * The deposit, as long as there is no paid deposit that has not been refunded
     * (separately or along with the stay).
     */
    private function depositCents(Booking $booking): int
    {
        return $booking->depositHeldCents() > 0 ? 0 : (int) $booking->deposit_cents;
    }

    private function paidAmountCents(Booking $booking): int
    {
        $overview = $booking->paymentOverview();

        return $overview['paid'] - $overview['refunded'];
    }

    /**
     * Should the guest pay the deposit now? When the accommodation collects it
     * right after booking, the booking is confirmed and the deposit is still open.
     */
    public function depositDueNow(Booking $booking): bool
    {
        return $booking->property?->depositAtBooking() === true
            && $booking->status->isConfirmed()
            && $this->depositCents($booking) > 0;
    }

    /**
     * Update the booking's payment status and move the booking status along.
     */
    private function applyPaymentToBooking(Booking $booking): void
    {
        $booking->refresh();
        $paidCents = $this->paidAmountCents($booking);

        $booking->payment_status = match (true) {
            $paidCents <= 0 && $booking->payments()->where('type', PaymentType::Refund->value)->exists() => PaymentStatus::Refunded,
            $paidCents >= $booking->total_cents => PaymentStatus::Paid,
            $paidCents > 0 => PaymentStatus::DownPaymentPaid,
            default => PaymentStatus::Unpaid,
        };
        $booking->save();

        // Move the booking status along: the first payment confirms the booking,
        // full payment sets it to paid.
        if ($paidCents > 0 && $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            $this->bookings->transition($booking, BookingStatus::Confirmed);
        }

        if ($paidCents >= $booking->total_cents
            && $booking->status->canTransitionTo(BookingStatus::Paid)) {
            $this->bookings->transition($booking, BookingStatus::Paid);
        }
    }
}
