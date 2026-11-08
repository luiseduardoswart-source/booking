<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Automatic discount on the stay, as on Airbnb:
 * - length of stay: from a number of nights (weekly, monthly discount);
 * - last minute: booked at most X days before arrival;
 * - early bird: booked at least X months before arrival.
 * A percentage or a fixed amount, the same types as for a coupon code.
 */
#[Fillable([
    'property_id', 'kind', 'name', 'name_translations',
    'min_nights', 'days_before_arrival', 'months_before_arrival',
    'type', 'percentage', 'amount_cents',
])]
class Discount extends Model
{
    use HasTranslations;

    public const TYPE_PERCENT = Coupon::TYPE_PERCENT;

    public const TYPE_FIXED = Coupon::TYPE_FIXED;

    public const STAY_DURATION = 'stay_duration';

    public const LASTMINUTE = 'lastminute';

    public const EARLY_BOOKING = 'early_booking';

    /** Kind => label (translation key), in the order of the select list. */
    public const KINDS = [
        self::STAY_DURATION => 'Length of stay (from a number of nights)',
        self::LASTMINUTE => 'Last minute (booked shortly before arrival)',
        self::EARLY_BOOKING => 'Early bird (booked well in advance)',
    ];

    protected $attributes = [
        'kind' => self::STAY_DURATION,
        'type' => self::TYPE_PERCENT,
    ];

    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    protected static function booted(): void
    {
        // Only the values that belong to the chosen kind are kept,
        // so nothing stale lingers after a switch.
        static::saving(function (Discount $discount): void {
            if ($discount->isFixedAmount()) {
                $discount->percentage = null;
            } else {
                $discount->amount_cents = null;
            }

            if ($discount->kind !== self::STAY_DURATION) {
                $discount->min_nights = null;
            }

            if ($discount->kind !== self::LASTMINUTE) {
                $discount->days_before_arrival = null;
            }

            if ($discount->kind !== self::EARLY_BOOKING) {
                $discount->months_before_arrival = null;
            }
        });
    }

    /** @return array<string, string> kind => label in the current language */
    public static function kindOptions(): array
    {
        return array_map(fn (string $label) => __($label), self::KINDS);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function isFixedAmount(): bool
    {
        return $this->type === self::TYPE_FIXED;
    }

    /**
     * Does this discount apply to a stay of $nights nights with arrival
     * on $arrival, booked on $booked?
     */
    public function appliesTo(int $nights, CarbonImmutable $arrival, CarbonImmutable $booked): bool
    {
        $daysInAdvance = (int) $booked->startOfDay()->diffInDays($arrival->startOfDay(), false);

        return match ($this->kind) {
            self::LASTMINUTE => $this->days_before_arrival !== null && $daysInAdvance >= 0 && $daysInAdvance <= $this->days_before_arrival,
            self::EARLY_BOOKING => $this->months_before_arrival !== null
                && $arrival->startOfDay()->greaterThanOrEqualTo($booked->startOfDay()->addMonthsNoOverflow($this->months_before_arrival)),
            default => $this->min_nights !== null && $nights >= $this->min_nights,
        };
    }

    /**
     * Discount amount in cents on the stay portion (never more than that portion),
     * in the booking's currency.
     */
    public function discountCents(int $stayCents, ?string $currency = null): int
    {
        $discount = $this->isFixedAmount()
            ? (int) $this->fixedAmount($currency)
            : (int) round($stayCents * min(100, (int) $this->percentage) / 100);

        return max(0, min($discount, $stayCents));
    }

    /**
     * Display of the discount, e.g. "10%" or "€ 50,00".
     */
    public function discountLabel(?string $currency = null): string
    {
        return $this->isFixedAmount()
            ? Money::format($this->fixedAmount($currency), $currency ?? $this->property?->priceCurrency())
            : (int) $this->percentage.'%';
    }

    /**
     * The line in the price breakdown for the guest: the own name followed by
     * the discount ("Weekly discount (10%)"), otherwise a description of the kind.
     */
    public function ruleForGuest(?string $currency = null): string
    {
        $discount = $this->discountLabel($currency);

        if (filled($name = $this->localized('name'))) {
            return "{$name} ({$discount})";
        }

        return match ($this->kind) {
            self::LASTMINUTE => __('Last-minute discount :discount', ['discount' => $discount]),
            self::EARLY_BOOKING => __('Early-bird discount :discount', ['discount' => $discount]),
            default => __('Discount :discount (from :nights nights)', ['discount' => $discount, 'nights' => $this->min_nights]),
        };
    }

    /**
     * When the discount applies, shown in the admin for each discount:
     * "From 7 nights", "Booked at most 14 days before arrival".
     *
     * @param  array<string, mixed>  $details  the fields of a single discount (form or model)
     */
    public static function condition(array $details): ?string
    {
        return match ($details['kind'] ?? self::STAY_DURATION) {
            self::LASTMINUTE => filled($details['days_before_arrival'] ?? null)
                ? trans_choice('{0} Booked on the day of arrival|{1} Booked at most 1 day before arrival|[2,*] Booked at most :count days before arrival', (int) $details['days_before_arrival'])
                : null,
            self::EARLY_BOOKING => filled($details['months_before_arrival'] ?? null)
                ? trans_choice('{1} Booked at least 1 month before arrival|[2,*] Booked at least :count months before arrival', (int) $details['months_before_arrival'])
                : null,
            default => filled($details['min_nights'] ?? null)
                ? __('From :count nights', ['count' => (int) $details['min_nights']])
                : null,
        };
    }

    /**
     * The fixed amount is in the currency of the accommodation the discount
     * belongs to (for the default: the default currency); converted to $currency.
     */
    private function fixedAmount(?string $currency): ?int
    {
        $custom = $this->property?->priceCurrency() ?? Money::defaultCurrency();

        return Money::convert($this->amount_cents, $custom, $currency ?? $custom);
    }
}
