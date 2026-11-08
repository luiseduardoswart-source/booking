<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'booking_id', 'type', 'status', 'amount_cents',
    'provider', 'provider_payment_id', 'refunded_payment_id', 'checkout_url', 'description', 'paid_at',
])]
class Payment extends Model
{
    use GuardedOwnership, LogsActivity;

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function refunds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'refunded_payment_id');
    }

    /**
     * Revenue received from these payments (paid down payments and
     * remainders), per currency of the booking: currency => cents. Amounts in
     * different currencies must not simply be added up; see
     * Money::inDefaultCurrency.
     *
     * @return array<string, int>
     */
    public static function revenuePerCurrency(Builder $query): array
    {
        return $query
            ->where('payments.status', PaymentTransactionStatus::Paid->value)
            ->whereIn('payments.type', [PaymentType::DownPayment->value, PaymentType::Balance->value])
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->groupBy('bookings.currency')
            ->selectRaw('bookings.currency as currency, sum(payments.amount_cents) as cents')
            ->pluck('cents', 'currency')
            ->map(fn ($cents) => (int) $cents)
            ->all();
    }

    /**
     * Where the guest returns after the payment provider: signed, so nobody
     * can view other payments by changing the number.
     */
    public function returnUrl(): string
    {
        return URL::signedRoute('payment.return', $this);
    }

    /**
     * The name of this payment as guest and admin see it, translated.
     */
    public function kind(): string
    {
        return __($this->booking->paymentKind($this->type));
    }

    public function hasOpenLink(): bool
    {
        return $this->status === PaymentTransactionStatus::Open && $this->checkout_url !== null;
    }

    /**
     * What can still be refunded of this payment: 0 if it is not paid,
     * is itself a refund, or has already been fully refunded.
     * Uses the sum from withSum('refunds', 'amount_cents') if present.
     */
    public function remainingRefundableCents(): int
    {
        if ($this->status !== PaymentTransactionStatus::Paid || $this->type === PaymentType::Refund) {
            return 0;
        }

        $alreadyBack = array_key_exists('refunds_sum_amount_cents', $this->attributes)
            ? (int) $this->refunds_sum_amount_cents
            : (int) $this->refunds()->sum('amount_cents');

        return max(0, $this->amount_cents - $alreadyBack);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_cents', 'provider_payment_id', 'paid_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'status' => PaymentTransactionStatus::class,
            'paid_at' => 'datetime',
        ];
    }
}
