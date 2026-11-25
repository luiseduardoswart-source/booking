<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Rules\StayLength;
use App\Support\Signature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'booking_number', 'property_id', 'status', 'payment_status', 'hold_until',
    'guest_name', 'guest_email', 'guest_phone',
    'guest_address', 'guest_postal_code', 'guest_city', 'guest_country', 'locale',
    'identity_status', 'identity_session_id', 'identity_overdue_at',
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
     * How long a booking made on the site holds its dates while the guest
     * pays (or does the ID check first). Not paid by then: the open payment
     * is closed and the booking is cancelled (bookings:release-unpaid), so
     * nobody can block the calendar with bookings that are never paid.
     */
    public const HOLD_MINUTES = 60;

    /** The longest stay a guest or the API can book, and how far ahead. */
    public const MAX_STAY_NIGHTS = 365;

    public const MAX_YEARS_AHEAD = 3;

    /**
     * The door code a smart lock gave this stay never leaves the model by
     * accident (JSON, API, logs). Not fillable either: only
     * App\Services\SmartLocks\SmartLockCodes sets it.
     */
    protected $hidden = ['lock_code'];

    /**
     * Validation of an arrival date entered by a guest or the API: today
     * or later. The calendar does not let you pick an earlier date, but with
     * the developer tools someone can send one anyway. One rule for all
     * places where a booking is made or a price is requested.
     *
     * Only a plain calendar date (2027-03-01): a date with a time or a time
     * zone behind it is a moment, and nights are not counted in moments
     * (see App\Support\CalendarDay).
     *
     * @return array<int, string>
     */
    public static function arrivalRule(): array
    {
        return ['required', 'date_format:Y-m-d', 'after_or_equal:today'];
    }

    /**
     * The departure date that goes with it: a plain calendar date after the
     * arrival, at most MAX_STAY_NIGHTS nights and MAX_YEARS_AHEAD years ahead.
     * The price request leaves the longest stay out: it answers that itself,
     * as "not available" with the reason, which the booking widget shows.
     *
     * @return array<int, mixed>
     */
    public static function departureRule(bool $longestStay = true): array
    {
        return [
            'required', 'date_format:Y-m-d', 'after:arrival',
            'before_or_equal:'.today()->addYears(self::MAX_YEARS_AHEAD)->toDateString(),
            ...($longestStay ? [new StayLength] : []),
        ];
    }

    /** @return array<string, string> */
    public static function arrivalNotification(): array
    {
        return [
            'arrival.after_or_equal' => __('The arrival date is in the past.'),
            'departure.before_or_equal' => __('Booking that far ahead is not yet possible (maximum :months months).', ['months' => 12 * self::MAX_YEARS_AHEAD]),
        ];
    }

    /**
     * Validation of the guest's phone number. Required when its last four
     * digits are the door code, and when the guest adds it later: then it
     * needs at least four digits. Always one line of text.
     *
     * @return array<int, string>
     */
    public static function phoneRule(bool $required): array
    {
        return $required
            ? ['required', 'string', 'max:50', 'regex:/(\D*\d){4}/', 'not_regex:/\p{Cc}/u']
            : ['nullable', 'string', 'max:50', 'not_regex:/\p{Cc}/u'];
    }

    /**
     * A guest who booked without a phone number can add one later, until
     * the stay is over. Only add, never change: its last digits can be the
     * door code.
     */
    public function phoneCanBeAdded(): bool
    {
        return blank($this->guest_phone)
            && ! in_array($this->status, [BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::Completed], true)
            && ! $this->departure_date->lt(today());
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function guestRegistrations(): HasMany
    {
        return $this->hasMany(GuestRegistration::class)->orderBy('position');
    }

    /** Everyone who comes, babies included: each of them is registered. */
    public function travellers(): int
    {
        return (int) $this->guests + (int) $this->infants;
    }

    /**
     * Does the accommodation ask the guest to register every traveller, and
     * has that not been done yet? Only for a stay that goes ahead.
     */
    public function guestRegistrationMissing(): bool
    {
        return (bool) (int) $this->property?->effective('guest_registration')
            && $this->status->isConfirmed()
            && $this->departure_date->greaterThanOrEqualTo(today())
            && $this->guestRegistrations()->count() < $this->travellers();
    }

    public function review(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Review::class);
    }

    /** True while ID verification is required and this booking has not been verified yet. */
    public function identityMissing(): bool
    {
        return $this->property->idRequired()
            && $this->identity_status !== \App\Enums\IdentityStatus::Verified;
    }

    /**
     * True while the check is still missing and has to come before paying —
     * then the guest may not yet be sent to payment. Not when the
     * accommodation lets the guest verify after paying, before arrival.
     */
    public function identityStillMissingBeforePayment(): bool
    {
        return $this->identityMissing() && ! $this->property->idAfterPayment();
    }

    /** With the check after the payment: the last day to do it. Otherwise null. */
    public function identityDeadline(): ?CarbonImmutable
    {
        return $this->property->idAfterPayment()
            ? $this->arrival_date->subDays($this->property->idDeadlineDays())
            : null;
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
        ['paid' => $paid, 'refunded' => $refunded] = self::stayPayments([$this->id])[$this->id];

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'outstanding' => max(0, $this->total_cents - ($paid - $refunded)),
        ];
    }

    /**
     * What has been paid and refunded for the stay of each of these bookings,
     * in cents, keyed by booking id: two queries for the whole list (the
     * export), the same sums as paymentOverview().
     *
     * @param  array<int, int>  $bookingIds
     * @return array<int, array{paid: int, refunded: int}>
     */
    public static function stayPayments(array $bookingIds): array
    {
        $stay = [PaymentType::DownPayment->value, PaymentType::Balance->value];

        // A deposit paid along with the stay (deposit right after booking) is
        // not part of what the stay costs, and neither is its refund.
        $sum = fn (Builder $payments) => $payments
            ->whereIn('booking_id', $bookingIds)
            ->groupBy('booking_id')
            ->selectRaw('booking_id, SUM(amount_cents - deposit_part_cents) AS cents')
            ->pluck('cents', 'booking_id');

        $paid = $sum(Payment::query()
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->whereIn('type', $stay));

        $refunded = $sum(Payment::query()
            ->where('type', PaymentType::Refund->value)
            ->whereIn('refunded_payment_id', Payment::query()->select('id')->whereIn('type', $stay)));

        return collect($bookingIds)
            ->mapWithKeys(fn (int $id): array => [$id => [
                'paid' => (int) ($paid[$id] ?? 0),
                'refunded' => (int) ($refunded[$id] ?? 0),
            ]])
            ->all();
    }

    /**
     * Has the guest paid part of the stay on the site while the rest is still
     * open? Also when a payment was given back or reversed by the bank. Then
     * the check-in instructions and the door code wait: a down payment
     * confirms the booking, it does not open the door.
     *
     * A booking the owner confirmed by hand, with nothing paid on the site
     * (cash, bank transfer), is the owner's own call and does not count.
     */
    public function owesBalance(): bool
    {
        $overview = $this->paymentOverview();

        return $overview['paid'] > 0 && $overview['outstanding'] > 0;
    }

    /** The deposit the guest has paid and not got back yet (deposit payment or along with the stay). */
    public function depositHeldCents(): int
    {
        return (int) $this->payments()
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->where(fn (Builder $query) => $query->where('type', PaymentType::Deposit->value)->orWhere('deposit_part_cents', '>', 0))
            ->get()
            ->sum(fn (Payment $payment) => $payment->depositHeldCents());
    }

    /** Bookings without a paid deposit (separately or along with the stay). */
    public function scopeWithoutPaidDeposit(Builder $query): Builder
    {
        return $query->whereDoesntHave('payments', fn (Builder $payments) => $payments
            ->where('status', PaymentTransactionStatus::Paid->value)
            ->where(fn (Builder $deposit) => $deposit->where('type', PaymentType::Deposit->value)->orWhere('deposit_part_cents', '>', 0)));
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
     * Payment link for the guest. The provider's link expires within hours;
     * this one lasts as long as the booking's other links (signedLink): it
     * leads to the running checkout or creates a new one.
     */
    public function paymentLink(PaymentType $type): string
    {
        return $this->signedLink('booking.pay', ['booking' => $this->id, 'type' => $type->value]);
    }

    /** How long after departure the guest's links keep working. */
    public const LINK_DAYS_AFTER_DEPARTURE = 90;

    /**
     * A signed link for this booking's guest (pay, confirmation, ID check,
     * review). It works until LINK_DAYS_AFTER_DEPARTURE days after departure,
     * not forever: a link in an old mail or a browser history does not open
     * the booking (with the guest's details) for good.
     *
     * @param  array<string, mixed>|null  $parameters  the booking itself when null
     */
    public function signedLink(string $route, ?array $parameters = null): string
    {
        return URL::temporarySignedRoute(
            $route,
            $this->departure_date->endOfDay()->addDays(self::LINK_DAYS_AFTER_DEPARTURE),
            $parameters ?? ['booking' => $this->id],
        );
    }

    /**
     * The accommodation page in the guest's language with the dates of this
     * booking filled in: to book them again after the booking lapsed.
     */
    public function rebookUrl(): string
    {
        return \App\Support\LocalizedUrl::of($this->property, $this->locale, [
            'arrival' => $this->arrival_date->toDateString(),
            'departure' => $this->departure_date->toDateString(),
        ]);
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
            'identity_overdue_at' => 'datetime',
            'hold_until' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'signed_at' => 'datetime',
            'arrival_date' => 'immutable_date:Y-m-d',
            'departure_date' => 'immutable_date:Y-m-d',
            'price_snapshot' => 'array',
            'form_answers' => 'array',
            'lock_code' => 'encrypted',
        ];
    }
}
