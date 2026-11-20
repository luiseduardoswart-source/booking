<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\BookingStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A booking made on the site that is not paid within the hold time gives its
 * dates back, so nobody can block the calendar with bookings that are never
 * paid. Whoever paid in time stays booked; bookings from the admin never expire.
 */
class ReleaseUnpaidBookingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');

        $this->property = Property::create([
            'name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function bookOnTheSite(array $extra = []): Booking
    {
        $this->post('/beach-house/book', [
            'arrival' => today()->addDays(40)->toDateString(),
            'departure' => today()->addDays(43)->toDateString(),
            'guests' => 2, 'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1',
            ...$extra,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Booking::query()->latest('id')->first();
    }

    public function test_a_site_booking_holds_its_dates_while_the_guest_pays(): void
    {
        $booking = $this->bookOnTheSite();

        $this->assertNotNull($booking->hold_until);
        $this->assertEqualsWithDelta(now()->addMinutes(Booking::HOLD_MINUTES)->timestamp, $booking->hold_until->timestamp, 5);

        // Within the hold time nothing happens.
        $this->travel(Booking::HOLD_MINUTES - 1)->minutes();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();
        $this->assertSame(BookingStatus::New, $booking->refresh()->status);
    }

    public function test_not_paid_in_time_the_booking_is_cancelled_and_the_dates_are_free(): void
    {
        $booking = $this->bookOnTheSite();
        $payment = $booking->payments()->sole();

        $this->travel(Booking::HOLD_MINUTES + 1)->minutes();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        $this->assertNull($booking->hold_until);
        // The payment page is closed, so nobody can pay for a cancelled booking.
        $this->assertSame(PaymentTransactionStatus::Cancelled, $payment->refresh()->status);

        // Somebody else can book those dates now.
        $this->bookOnTheSite();
        $this->assertSame(2, Booking::count());
    }

    public function test_whoever_paid_at_the_last_minute_stays_booked(): void
    {
        $booking = $this->bookOnTheSite();
        // Paid, but the webhook has not arrived yet.
        $this->provider->setStatus((string) $booking->payments()->sole()->provider_payment_id, ProviderPaymentStatus::Paid);

        $this->travel(Booking::HOLD_MINUTES + 1)->minutes();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();

        $booking->refresh();
        $this->assertNotSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(PaymentTransactionStatus::Paid, $booking->payments()->sole()->status);
    }

    public function test_repeating_the_payment_start_cannot_hold_the_dates_for_ever(): void
    {
        $booking = $this->bookOnTheSite();

        // For example by doing the ID check again and again.
        $this->travel(110)->minutes();
        app(\App\Engines\PaymentEngine::class)->checkoutOrConfirmationUrl($booking->refresh());

        $this->assertLessThanOrEqual(
            $booking->created_at->copy()->addMinutes(2 * Booking::HOLD_MINUTES)->timestamp,
            $booking->refresh()->hold_until->timestamp,
        );
    }

    public function test_a_booking_made_in_the_admin_never_expires(): void
    {
        $booking = app(BookingEngine::class)->create(
            $this->property,
            CarbonImmutable::today()->addDays(40),
            CarbonImmutable::today()->addDays(43),
            2,
            ['name' => 'Phone guest', 'email' => 'phone@example.com'],
        );

        $this->travel(2)->days();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();

        $this->assertNull($booking->refresh()->hold_until);
        $this->assertSame(BookingStatus::New, $booking->status);
    }

    public function test_without_an_online_payment_the_booking_stands_and_never_expires(): void
    {
        // Mollie chosen but no key set yet: no online payment, the guest gets
        // the confirmation and the booking stands.
        Setting::set('payments', 'provider', 'mollie');
        $this->app->forgetInstance(PaymentProvider::class);

        $booking = $this->bookOnTheSite();

        $this->assertNull($booking->hold_until);

        $this->travel(2)->days();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();
        $this->assertSame(BookingStatus::New, $booking->refresh()->status);
    }

    public function test_the_coupon_of_a_released_booking_can_be_used_again(): void
    {
        $coupon = Coupon::create(['code' => 'SUMMER', 'type' => 'percent', 'value' => 10, 'max_uses' => 1, 'active' => true]);

        $this->bookOnTheSite(['coupon' => 'summer']);
        $this->assertSame(1, $coupon->refresh()->used_count);

        $this->travel(Booking::HOLD_MINUTES + 1)->minutes();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();

        $this->assertSame(0, $coupon->refresh()->used_count);
    }
}
