<?php

namespace App\Engines;

use App\Engines\Pricing\Quote;
use App\Engines\Pricing\QuoteLine;
use App\Models\Discount;
use App\Models\NightlyRate;
use App\Models\Property;
use App\Models\RateSeason;
use App\Support\CalendarDay;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use InvalidArgumentException;

/**
 * One central place where prices are calculated. Website, admin and API
 * all use this engine, so the result is the same everywhere.
 *
 * Build-up: base price/season per night (weekend = Fri/Sat night)
 * → extra guests → discount → cleaning → tourist tax → total.
 */
class PricingEngine
{
    public function quote(
        Property $property,
        CarbonImmutable $arrival,
        CarbonImmutable $departure,
        int $guests,
        int $pets = 0,
        ?\App\Models\Coupon $coupon = null,
    ): Quote {
        // Calendar days, not moments: the nights priced are the nights stored (see CalendarDay).
        $arrival = CalendarDay::of($arrival);
        $departure = CalendarDay::of($departure);

        if ($departure->lessThanOrEqualTo($arrival)) {
            throw new InvalidArgumentException('The check-out date must be after the check-in date.');
        }

        if ($guests < 1) {
            throw new InvalidArgumentException('The number of guests must be at least 1.');
        }

        if ($guests > $property->max_guests) {
            throw new InvalidArgumentException(
                "This property accommodates at most {$property->max_guests} guests."
            );
        }

        $nights = (int) $arrival->diffInDays($departure);

        // All amounts in the property's currency.
        $currency = $property->priceCurrency();

        // Amounts, seasons, discounts and a coupon of the default are converted
        // at the exchange rate. Without a rate Money::convert leaves the number
        // as it is (euros read as pesos): better no price than a wrong one.
        if (Money::exchangeRate(Money::defaultCurrency(), $currency) === null) {
            throw new InvalidArgumentException(__('The price cannot be calculated right now. Please try again in a few minutes.'));
        }

        // Fall back to the defaults: own seasons/discounts win;
        // if the property has none, those of the master record apply.
        $seasons = $this->seasons($property);

        // Night overrides (manual or PriceLabs) beat season/base price.
        $overrides = $this->overridesMap($property, $arrival, $departure->subDay());

        $lines = [];

        // 1. Stay: the right price per night
        //    (night override > season > default, weekend > weekday)
        $accommodationCents = 0;

        foreach (CarbonPeriod::create($arrival, $departure->subDay()) as $night) {
            $accommodationCents += $this->nightPriceCents($property, $seasons, $overrides, CarbonImmutable::instance($night));
        }

        $lines[] = new QuoteLine('accommodation', $nights === 1
            ? __('Stay (1 night)')
            : __('Stay (:nights nights)', ['nights' => $nights]), $accommodationCents);

        // 2. Extra guest surcharge (above the included number, p.p. per night)
        $extraGuestCents = 0;
        $includedGuests = $property->effective('included_guests');

        if ($includedGuests !== null && $guests > $includedGuests) {
            $extraGuests = $guests - $includedGuests;
            $extraGuestCents = $extraGuests * (int) $property->effectiveAmount('extra_guest_fee_cents') * $nights;

            if ($extraGuestCents > 0) {
                $lines[] = new QuoteLine('extra_guests', __('Extra guest fee (:count)', ['count' => $extraGuests]), $extraGuestCents);
            }
        }

        // 3. Automatic discounts (length of stay, last minute, early bird) over
        //    stay + extra guests, not over cleaning/tax. Only the most
        //    favourable, or all that apply if the property stacks them;
        //    together never more than the stay.
        $stayCents = $accommodationCents + $extraGuestCents;
        $discountCents = 0;

        foreach ($this->discounts($property, $nights, $arrival, $stayCents, $currency) as $discount) {
            $amount = min($discount->discountCents($stayCents, $currency), $stayCents - $discountCents);

            if ($amount > 0) {
                $discountCents += $amount;
                $lines[] = new QuoteLine('discount', $discount->ruleForGuest($currency), -$amount);
            }
        }

        // 3b. Coupon code: over the stay part after the volume discount,
        //     not over cleaning/tax/deposit. Validity has already been
        //     checked by the caller (Coupon::invalidBecause).
        $couponCents = 0;

        if ($coupon !== null) {
            $couponCents = $coupon->discountCents($accommodationCents + $extraGuestCents - $discountCents, $currency);

            if ($couponCents > 0) {
                $lines[] = new QuoteLine('coupon', __('Coupon :code (:discount)', [
                    'code' => strtoupper($coupon->code),
                    'discount' => $coupon->discountLabel($currency),
                ]), -$couponCents);
            }
        }

        // 4. Cleaning fee (one-off)
        $cleaningCents = (int) $property->effectiveAmount('cleaning_fee_cents');

        if ($cleaningCents > 0) {
            $lines[] = new QuoteLine('cleaning', __('Cleaning fee'), $cleaningCents);
        }

        // 5. Tourist tax (per person per night, per night or per stay)
        $touristTaxCents = $property->touristTax($guests, $nights);

        if ($touristTaxCents > 0) {
            $lines[] = new QuoteLine('tourist_tax', __('Tourist tax'), $touristTaxCents);
        }

        // 6. Pet fee (one-off, per pet)
        $petFeeCents = (int) $property->effectiveAmount('pet_fee_cents') * max(0, $pets);

        if ($petFeeCents > 0) {
            $lines[] = new QuoteLine('pet_fee', trans_choice('{1} Pet fee|[2,*] Fee for :count pets', max(0, $pets)), $petFeeCents);
        }

        $totalCents = $accommodationCents + $extraGuestCents - $discountCents - $couponCents
            + $cleaningCents + $touristTaxCents + $petFeeCents;

        // 6. Down payment / remainder (percentage of the property or the default)
        $downPaymentPercentage = (int) $property->effective('down_payment_percentage');
        $downPaymentCents = $downPaymentPercentage >= Property::FULL_PAYMENT
            ? $totalCents
            : (int) round($totalCents * $downPaymentPercentage / 100);

        return new Quote(
            arrival: $arrival,
            departure: $departure,
            nights: $nights,
            guests: $guests,
            lines: $lines,
            totalCents: $totalCents,
            depositCents: (int) $property->effectiveAmount('deposit_cents'),
            downPaymentCents: $downPaymentCents,
            remainingCents: $totalCents - $downPaymentCents,
            currency: $currency,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, RateSeason>  $seasons
     * @param  array<string, int>  $overrides  date (Y-m-d) => price in cents
     */
    private function nightPriceCents(Property $property, $seasons, array $overrides, CarbonImmutable $night): int
    {
        // 1. Night override (manual or PriceLabs) always takes precedence.
        $key = $night->toDateString();
        if (isset($overrides[$key])) {
            return $overrides[$key];
        }

        $isWeekend = in_array($night->dayOfWeekIso, [5, 6], true); // Friday and Saturday night

        // 2. Season price.
        $season = $seasons->first(
            fn (RateSeason $season) => $night->betweenIncluded($season->start_date, $season->end_date)
        );

        if ($season !== null) {
            if (! $isWeekend) {
                return $season->price_cents;
            }

            // A season's own weekend price comes first; otherwise the
            // weekend adjustment if that is a percentage.
            return $season->weekend_price_cents
                ?? ($property->weekendKind() === Property::WEEKEND_PERCENT
                    ? $property->weekendPrice($season->price_cents)
                    : $season->price_cents);
        }

        // 3. Base/weekend price (falling back to the defaults).
        $basePrice = (int) ($property->effectiveAmount('base_price_cents') ?? 10000);

        return $isWeekend ? $property->weekendPrice($basePrice) : $basePrice;
    }

    /**
     * The discounts that apply: the property's own discounts, otherwise
     * those of the default. For length of stay the highest threshold reached
     * counts (as always). If the property stacks discounts, they all apply
     * (largest first); otherwise only the most favourable.
     * Booking happens today.
     *
     * @return \Illuminate\Support\Collection<int, Discount>
     */
    private function discounts(Property $property, int $nights, CarbonImmutable $arrival, int $stayCents, string $currency)
    {
        $owner = $property->discounts()->exists() || $property->is_master
            ? $property
            : Property::master();

        $today = CarbonImmutable::today();
        $discounts = $owner->discounts()->get()
            // A fixed amount from the default is in the default currency.
            ->each(fn (Discount $discount) => $discount->setRelation('property', $owner))
            ->filter(fn (Discount $discount) => $discount->appliesTo($nights, $arrival, $today));

        $highestThreshold = $discounts
            ->where('kind', Discount::STAY_DURATION)
            ->sortByDesc('min_nights')
            ->first();

        $apply = $discounts
            ->reject(fn (Discount $discount) => $discount->kind === Discount::STAY_DURATION && ! $discount->is($highestThreshold))
            ->sortByDesc(fn (Discount $discount) => $discount->discountCents($stayCents, $currency))
            ->values();

        return $property->discountsAddUp() ? $apply : $apply->take(1);
    }

    /**
     * The property's seasons, otherwise those of the default; default prices
     * converted if it has a different currency.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RateSeason>
     */
    private function seasons(Property $property)
    {
        $seasons = $property->rateSeasons()->get();

        if ($seasons->isNotEmpty() || $property->is_master) {
            return $seasons;
        }

        $default = Property::master();
        $of = $default->priceCurrency();
        $to = $property->priceCurrency();

        return $default->rateSeasons()->get()->each(function (RateSeason $season) use ($of, $to) {
            $season->price_cents = Money::convert($season->price_cents, $of, $to);
            $season->weekend_price_cents = Money::convert($season->weekend_price_cents, $of, $to);
        });
    }

    /**
     * Night overrides for a period (up to and including the last night), keyed by date.
     * Prices from the dynamic pricing service come minus the discount on
     * dynamic prices; prices set by hand stay exactly as entered.
     *
     * @return array<string, int>
     */
    private function overridesMap(Property $property, CarbonImmutable $from, CarbonImmutable $toLastNight): array
    {
        [$dynamic, $manual] = $property->nightlyRates()
            ->whereBetween('date', [$from->toDateString(), $toLastNight->toDateString()])
            ->get(['date', 'price_cents', 'source'])
            ->partition(fn (NightlyRate $rate) => $rate->source === NightlyRate::SOURCE_PRICELABS)
            ->map(fn ($rates) => $rates->mapWithKeys(fn (NightlyRate $rate) => [$rate->date->toDateString() => $rate->price_cents])->all());

        return $property->withDynamicDiscount($dynamic) + $manual;
    }

    /**
     * Effective price per night for a period — for the calendar view
     * (site and admin). Returns date (Y-m-d) => price in cents.
     *
     * @return array<string, int>
     */
    public function nightlyPriceMap(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = CalendarDay::of($from);
        $to = CalendarDay::of($to);

        $seasons = $this->seasons($property);

        $overrides = $this->overridesMap($property, $from, $to);

        $prices = [];
        foreach (CarbonPeriod::create($from, $to) as $night) {
            $nightImmutable = CarbonImmutable::instance($night);
            $prices[$nightImmutable->toDateString()] = $this->nightPriceCents($property, $seasons, $overrides, $nightImmutable);
        }

        return $prices;
    }
}
