<?php

namespace App\Observers;

use App\Enums\AdminNotification;
use App\Enums\PaymentTransactionStatus;
use App\Models\Payment;
use App\Support\AdminNotifications;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Notification to owner and host as soon as a payment (deposit, balance or
 * security deposit) has come in. Refunds do not count.
 */
class PaymentNotifications implements ShouldHandleEventsAfterCommit
{
    public function created(Payment $payment): void
    {
        $this->notifyAsPaid($payment);
    }

    public function updated(Payment $payment): void
    {
        if ($payment->wasChanged('status')) {
            $this->notifyAsPaid($payment);
        }
    }

    private function notifyAsPaid(Payment $payment): void
    {
        // A refund gets the status "refunded", never "paid".
        if ($payment->status === PaymentTransactionStatus::Paid) {
            AdminNotifications::send(AdminNotification::Payment, $payment->booking, $payment);
        }
    }
}
