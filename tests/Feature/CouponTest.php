<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PricingEngine;
use App\Models\Coupon;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function property(): Property
    {
        return Property::create([
            'name' => 'Couponhuis', 'slug' => 'couponhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'country' => 'NL', 'cancellation_policy' => 'moderate',
        ]);
    }

    public function test_percentage_discount_lowers_the_stay_part(): void
    {
        $property = $this->property();
        $coupon = Coupon::create(['code' => 'TIEN', 'type' => Coupon::TYPE_PERCENT, 'value' => 10]);

        $tomorrow = CarbonImmutable::tomorrow();
        $quote = app(PricingEngine::class)->quote($property, $tomorrow, $tomorrow->addDays(4), 2, 0, $coupon);

        // 4 nachten à €100 = €400; 10% = €40 korting.
        $couponLine = collect($quote->lines)->firstWhere('key', 'coupon');
        $this->assertNotNull($couponLine);
        $this->assertSame(-4000, $couponLine->amountCents);
        $this->assertSame(36000, $quote->totalCents);
    }

    public function test_fixed_amount_goes_never_under_zero(): void
    {
        $coupon = new Coupon(['type' => Coupon::TYPE_FIXED, 'value' => 999999]);

        $this->assertSame(40000, $coupon->discountCents(40000));
    }

    public function test_validity_rules(): void
    {
        $property = $this->property();
        $other = Property::create([
            'name' => 'Ander huis', 'slug' => 'ander-huis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
        $tomorrow = CarbonImmutable::tomorrow();

        $inactive = Coupon::create(['code' => 'A', 'type' => 'percent', 'value' => 10, 'active' => false]);
        $this->assertNotNull($inactive->invalidBecause($property, $tomorrow));

        $otherAccommodation = Coupon::create(['code' => 'B', 'type' => 'percent', 'value' => 10, 'property_id' => $other->id]);
        $this->assertNotNull($otherAccommodation->invalidBecause($property, $tomorrow));
        $this->assertNull($otherAccommodation->invalidBecause($other, $tomorrow));

        $expired = Coupon::create(['code' => 'C', 'type' => 'percent', 'value' => 10, 'valid_until' => now()->subDay()->toDateString()]);
        $this->assertNotNull($expired->invalidBecause($property, $tomorrow));

        $op = Coupon::create(['code' => 'D', 'type' => 'percent', 'value' => 10, 'max_uses' => 1, 'used_count' => 1]);
        $this->assertNotNull($op->invalidBecause($property, $tomorrow));

        $valid = Coupon::create(['code' => 'E', 'type' => 'percent', 'value' => 10]);
        $this->assertNull($valid->invalidBecause($property, $tomorrow));
    }

    public function test_search_is_case_insensitive(): void
    {
        Coupon::create(['code' => 'ZOMER10', 'type' => 'percent', 'value' => 10]);

        $this->assertNotNull(Coupon::search('zomer10'));
        $this->assertNotNull(Coupon::search('  Zomer10 '));
        $this->assertNull(Coupon::search('BESTAATNIET'));
    }

    public function test_checkout_shows_discount_and_error_message(): void
    {
        $property = $this->property();
        Coupon::create(['code' => 'WELKOM', 'type' => Coupon::TYPE_PERCENT, 'value' => 15]);

        $tomorrow = CarbonImmutable::tomorrow();
        $params = [
            'arrival' => $tomorrow->toDateString(),
            'departure' => $tomorrow->addDays(2)->toDateString(),
            'guests' => 2,
        ];

        // Geldige code: kortingsregel in het overzicht.
        $this->withSession(['locale' => 'nl'])
            ->get(route('bookings.create', array_merge(['property' => $property], $params, ['coupon' => 'welkom'])))
            ->assertOk()
            ->assertSee('WELKOM')
            ->assertSee(__('Coupon :code applied', ['code' => 'WELKOM']));

        // Onbekende code: nette melding.
        $this->withSession(['locale' => 'nl'])
            ->get(route('bookings.create', array_merge(['property' => $property], $params, ['coupon' => 'FOUT'])))
            ->assertOk()
            ->assertSee(__('This coupon code does not exist.'));
    }

    public function test_book_with_code_increases_usage_and_saves_snapshot(): void
    {
        $property = $this->property();
        $coupon = Coupon::create(['code' => 'ZOMER10', 'type' => Coupon::TYPE_PERCENT, 'value' => 10, 'max_uses' => 5]);

        $tomorrow = CarbonImmutable::tomorrow();

        $booking = app(BookingEngine::class)->create(
            property: $property,
            arrival: $tomorrow,
            departure: $tomorrow->addDays(4),
            guests: 2,
            guest: ['name' => 'Jan', 'email' => 'jan@example.com'],
            couponCode: 'zomer10',
        );

        $this->assertSame('ZOMER10', $booking->coupon_code);
        $this->assertSame(36000, $booking->total_cents);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_a_used_up_code_stops_the_booking(): void
    {
        $property = $this->property();
        Coupon::create(['code' => 'OP', 'type' => Coupon::TYPE_PERCENT, 'value' => 50, 'max_uses' => 1, 'used_count' => 1]);

        $tomorrow = CarbonImmutable::tomorrow();

        // The guest was shown a price with the code: no booking at the full
        // price behind their back.
        try {
            app(BookingEngine::class)->create(
                property: $property,
                arrival: $tomorrow,
                departure: $tomorrow->addDays(2),
                guests: 2,
                guest: ['name' => 'Jan', 'email' => 'jan@example.com'],
                couponCode: 'OP',
            );
            $this->fail('The booking was made without the coupon.');
        } catch (\App\Engines\Exceptions\InvalidCouponException $e) {
            $this->assertSame(__('This coupon code has already been used the maximum number of times.'), $e->getMessage());
        }

        $this->assertSame(0, \App\Models\Booking::count());
    }
}
