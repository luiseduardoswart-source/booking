<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Support\Signature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'booking_number', 'property_id', 'status', 'payment_status',
    'guest_name', 'guest_email', 'guest_phone',
    'guest_address', 'guest_postal_code', 'guest_city', 'guest_country', 'locale',
    'identity_status', 'identity_session_id',
    'arrival_date', 'departure_date', 'guests',
    'adults', 'children', 'infants', 'pets',
    'notes', 'form_answers', 'internal_notes', 'terms_accepted_at', 'terms_snapshot',
    'price_snapshot', 'total_cents', 'currency', 'down_payment_cents', 'deposit_cents',
    'coupon_code',
])]
class Booking extends Model
{
    use GuardedOwnership, LogsActivity;

    /**
     * Statuses that do not keep dates occupied — only these are not counted
     * in the availability check.
     */
    public const INACTIVE_STATUSES = ['cancelled'];

    /**
     * Validation of an arrival date entered by a guest or the API: today
     * or later. The calendar does not let you pick an earlier date, but with
     * the developer tools someone can send one anyway. One rule for all
     * places where a booking is made or a price is requested.
     *
     * @return array<int, string>
     */
    public static function arrivalRule(): array
    {
        return ['required', 'date', 'after_or_equal:today'];
    }

    /** @return array<string, string> */
    public static function arrivalNotification(): array
    {
        return ['arrival.after_or_equal' => __('The arrival date is in the past.')];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * True while ID verification is required and this booking has not yet
     * been verified — then the guest may not yet be sent to payment.
     */
    public function identityStillMissingBeforePayment(): bool
    {
        return $this->property->idRequired()
            && $this->identity_status !== \App\Enums\IdentityStatus::Verified;
    }

    /**
     * What has been paid, refunded and is still to be paid for the stay, in
     * cents. The deposit is separate: it does not count towards the total, so
     * a returned deposit does not either.
     *
     * @return array{paid: int, refunded: int, outstanding: int}
     */
    public function paymentOverview(): array
    {
        $stay = [PaymentType::DownPayment->value, PaymentType::Balance->value];

        $paid = (int) $this->payments()
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->whereIn('type', $stay)
            ->sum('amount_cents');

        $refunded = (int) $this->payments()
            ->where('type', PaymentType::Refund->value)
            ->whereIn('refunded_payment_id', Payment::query()->select('id')->whereIn('type', $stay))
            ->sum('amount_cents');

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'outstanding' => max(0, $this->total_cents - ($paid - $refunded)),
        ];
    }

    /**
     * True if the guest pays everything at once: then there is no down payment
     * and no remainder, even though the payment is still called that internally.
     */
    public function paysInFull(): bool
    {
        return $this->down_payment_cents >= $this->total_cents;
    }

    /**
     * The name of a payment for this booking (the translation key).
     */
    public function paymentKind(PaymentType $type): string
    {
        return $type === PaymentType::DownPayment && $this->paysInFull()
            ? 'Full payment'
            : $type->label();
    }

    /**
     * The payments whose link is still open, newest first.
     *
     * @return Collection<int, Payment>
     */
    public function openPayments(): Collection
    {
        return $this->payments()
            ->where('status', PaymentTransactionStatus::Open->value)
            ->whereNotNull('checkout_url')
            ->latest('id')
            ->get();
    }

    /**
     * Permanent payment link for the guest. The provider's link expires; this
     * one does not: it leads to the running checkout or creates a new one.
     */
    public function paymentLink(PaymentType $type): string
    {
        return URL::signedRoute('booking.pay', ['booking' => $this->id, 'type' => $type->value]);
    }

    public function nights(): int
    {
        return (int) $this->arrival_date->diffInDays($this->departure_date);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'payment_status', 'arrival_date', 'departure_date', 'guests', 'guest_name', 'guest_email'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * The signature as a data URI, for the PDF. Null if nothing was
     * signed or the file is missing.
     *
     * The signature fields are deliberately not in Fillable: only the
     * booking logic sets them (via App\Support\Signature).
     */
    public function signatureAsDataUri(): ?string
    {
        if ($this->signature_path === null || ! Storage::disk('local')->exists($this->signature_path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($this->signature_path));
    }

    /**
     * Is the stored text still exactly what was signed? Compares the
     * fingerprint of now with that of the moment of signing.
     */
    public function signedTextUnchanged(): bool
    {
        return $this->signature_hash !== null
            && hash_equals($this->signature_hash, Signature::fingerprint((string) $this->terms_snapshot));
    }

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
            'identity_status' => \App\Enums\IdentityStatus::class,
            'terms_accepted_at' => 'datetime',
            'signed_at' => 'datetime',
            'arrival_date' => 'immutable_date:Y-m-d',
            'departure_date' => 'immutable_date:Y-m-d',
            'price_snapshot' => 'array',
            'form_answers' => 'array',
        ];
    }
}
