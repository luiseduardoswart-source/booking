<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\DocumentEngine;
use App\Engines\PaymentEngine;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\GuestLogin;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\MollieProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use App\Payments\StripeProvider;
use App\Support\GuestAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Before going live: test bookings and everything that hangs on them away,
 * numbering back to 0001. Never when a real (live) payment is among them.
 */
class ClearTestBookingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    private Property $accommodation;

    private int $weeks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    /** A paid booking with an invoice, as a test on the site leaves it behind. */
    private function paidBooking(): Booking
    {
        $week = $this->weeks++;

        $booking = app(BookingEngine::class)->create(
            $this->accommodation,
            CarbonImmutable::parse('2026-03-02')->addWeeks($week),
            CarbonImmutable::parse('2026-03-05')->addWeeks($week),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $engine = app(PaymentEngine::class);
        $payment = $engine->createPayment($booking, PaymentType::DownPayment);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $engine->syncFromProvider($payment);
        app(DocumentEngine::class)->findOrCreateInvoice($booking);

        return $booking->refresh();
    }

    public function test_a_payment_remembers_whether_it_was_made_with_a_test_key(): void
    {
        $this->assertTrue($this->paidBooking()->payments()->first()->test_mode);

        Setting::set('payments', 'mollie_api_key', 'test_abc');
        $this->assertTrue((new MollieProvider)->isTestMode());
        Setting::set('payments', 'mollie_api_key', 'live_abc');
        $this->assertFalse((new MollieProvider)->isTestMode());

        Setting::set('payments', 'stripe_secret_key', 'sk_test_abc');
        $this->assertTrue((new StripeProvider)->isTestMode());
        Setting::set('payments', 'stripe_secret_key', 'sk_live_abc');
        $this->assertFalse((new StripeProvider)->isTestMode());

        // Without a key no real money can move either.
        Setting::set('payments', 'mollie_api_key', '');
        $this->assertTrue((new MollieProvider)->isTestMode());
        Setting::set('payments', 'stripe_secret_key', '');
        $this->assertTrue((new StripeProvider)->isTestMode());
    }

    /** Mollie without a key (or in a currency it does not charge in): no half payment stays behind. */
    public function test_a_payment_the_provider_refuses_leaves_nothing_behind(): void
    {
        $booking = $this->paidBooking();
        $before = Payment::count();
        $this->app->instance(PaymentProvider::class, new MollieProvider);
        Setting::set('payments', 'mollie_api_key', '');

        try {
            app(PaymentEngine::class)->createPayment($booking, PaymentType::Balance);
            $this->fail('Mollie without a key must refuse.');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, Payment::count());
    }

    /** Left behind before that was fixed: never reached the provider, so never real money. */
    public function test_a_payment_that_never_reached_the_provider_does_not_stop_the_clearing(): void
    {
        $this->paidBooking()->payments()->create([
            'type' => PaymentType::Balance, 'status' => \App\Enums\PaymentTransactionStatus::Open,
            'amount_cents' => 181800000, 'provider' => 'mollie', 'test_mode' => false,
        ]);

        $this->artisan('bookings:clear-test-data')
            ->expectsConfirmation('Delete all of this permanently?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, Payment::count());
    }

    public function test_it_shows_what_goes_and_removes_nothing_without_a_yes(): void
    {
        $this->paidBooking();

        $this->artisan('bookings:clear-test-data')
            ->expectsOutputToContain('Bookings')
            ->expectsConfirmation('Delete all of this permanently?', 'no')
            ->assertExitCode(1);

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_everything_of_the_test_bookings_goes_and_the_numbering_starts_again(): void
    {
        Storage::fake('local');
        $booking = $this->paidBooking();
        $this->paidBooking();
        Storage::disk('local')->put('signatures/test.png', 'png');
        DB::table('bookings')->where('id', $booking->id)->update(['signature_path' => 'signatures/test.png']);
        Storage::disk('local')->put('signatures/someone-else.png', 'png');
        GuestAccess::issueLink('jan@example.com');
        Coupon::create(['code' => 'TEST', 'type' => 'percent', 'value' => 10, 'used_count' => 3]);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Mail\\BookingMail', 'data' => ['booking' => $booking->id]]),
            'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        $this->assertGreaterThan(0, DB::table('activity_log')->where('subject_type', $booking->getMorphClass())->count());

        $this->artisan('bookings:clear-test-data')
            ->expectsConfirmation('Delete all of this permanently?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('sent_mails')->count());
        $this->assertSame(0, GuestLogin::count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('activity_log')->where('subject_type', $booking->getMorphClass())->count());
        $this->assertSame(0, Coupon::first()->used_count);
        Storage::disk('local')->assertMissing('signatures/test.png');
        Storage::disk('local')->assertExists('signatures/someone-else.png');

        // The accommodation and the settings stay.
        $this->assertSame(1, Property::count());
        $this->assertSame('fake', Setting::get('payments', 'provider'));

        // The first real booking and invoice are number 1 again. On MariaDB the
        // booking id restarts with ALTER TABLE, which is not done inside the
        // transaction this test runs in; that part is checked on SQLite.
        $next = $this->paidBooking();
        $this->assertSame(sprintf('F-%d-0001', now()->year), Invoice::where('booking_id', $next->id)->value('invoice_number'));

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->assertSame(sprintf('BK-%d-0001', now()->year), $next->booking_number);
    }

    public function test_it_refuses_when_a_real_payment_is_among_them(): void
    {
        $this->paidBooking()->payments()->first()->update(['test_mode' => false]);

        $this->artisan('bookings:clear-test-data')
            ->expectsOutputToContain('real payment')
            ->assertExitCode(1);

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_payments_of_unknown_kind_are_asked_about_separately(): void
    {
        $booking = $this->paidBooking();
        $booking->payments()->first()->update(['test_mode' => null]);

        $this->artisan('bookings:clear-test-data')
            ->expectsOutputToContain($booking->booking_number)
            ->expectsConfirmation('These payments were made before this was remembered. Are they all test payments?', 'no')
            ->assertExitCode(1);

        $this->assertSame(1, Booking::count());

        $this->artisan('bookings:clear-test-data')
            ->expectsConfirmation('These payments were made before this was remembered. Are they all test payments?', 'yes')
            ->expectsConfirmation('Delete all of this permanently?', 'yes')
            ->assertExitCode(0);

        $this->assertSame(0, Booking::count());
    }

    public function test_without_bookings_there_is_nothing_to_do(): void
    {
        $this->artisan('bookings:clear-test-data')
            ->expectsOutputToContain('No bookings')
            ->assertExitCode(0);
    }
}
