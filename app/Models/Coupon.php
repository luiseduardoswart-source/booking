<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'code', 'description', 'type', 'value', 'property_id',
    'valid_from', 'valid_until', 'max_uses', 'used_count', 'active',
])]
class Coupon extends Model
{
    use GuardedOwnership;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    // Also correct right after Coupon::create() without explicit values.
    protected $attributes = [
        'active' => true,
        'used_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Codes are case-insensitive and without spaces.
     */
    public static function search(?string $code, bool $lock = false): ?self
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : static::query()
            ->whereRaw('upper(code) = ?', [$code])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Reason why this code is not valid for this booking, or null.
     */
    public function invalidBecause(Property $property, CarbonImmutable $arrival): ?string
    {
        if (! $this->active) {
            return __('This coupon code is no longer valid.');
        }

        if ($this->property_id !== null && $this->property_id !== $property->id) {
            return __('This coupon code is not valid for this accommodation.');
        }

        $today = CarbonImmutable::today();

        if ($this->valid_from !== null && $today->lessThan($this->valid_from)) {
            return __('This coupon code is not valid yet.');
        }

        if ($this->valid_until !== null && $today->greaterThan($this->valid_until)) {
            return __('This coupon code has expired.');
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return __('This coupon code has already been used the maximum number of times.');
        }

        return null;
    }

    /**
     * Discount amount in cents over the stay portion (never more than that portion),
     * in the booking's currency.
     */
    public function discountCents(int $stayCents, ?string $currency = null): int
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? (int) round($stayCents * min(100, $this->value) / 100)
            : (int) $this->fixedAmount($currency);

        return max(0, min($discount, $stayCents));
    }

    /**
     * Display of the discount, e.g. "10%" or "€ 25,00".
     */
    public function discountLabel(?string $currency = null): string
    {
        return $this->type === self::TYPE_PERCENT
            ? $this->value.'%'
            : Money::format($this->fixedAmount($currency), $currency ?? $this->priceCurrency());
    }

    /**
     * Currency of a fixed amount: that of the accommodation the code
     * applies to, for "all accommodations" the default currency.
     */
    public function priceCurrency(): string
    {
        return $this->property?->priceCurrency() ?? Money::defaultCurrency();
    }

    /** The fixed amount, converted to $currency. */
    private function fixedAmount(?string $currency): ?int
    {
        return Money::convert($this->value === null ? null : (int) $this->value, $this->priceCurrency(), $currency ?? $this->priceCurrency());
    }
}
