<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A down payment confirms the booking, but it does not open the door: the
 * check-in instructions and the door code wait until the stay is paid.
 */
class CheckInWaitsForBalanceTest extends TestCase
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
        $this->travelTo('2027-03-01 08:00:00');

        // 30% down payment; self check-in with one code for the door.
        $this->property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
            'base_price_cents' => 10000, 'down_payment_percentage' => 30, 'checkin_days_before_arrival' => 3,
            'check_in_kind' => Property::CHECK_IN_SELF, 'access_code_kind' => Property::ACCESS_FIXED, 'access_code' => '4711',
        ]);
    }

    /** Arrival the day after tomorrow: inside the days the check-in mail goes out. */
    private function booking(): Booking
    {
        return app(BookingEngine::class)->create(
            $this->property, CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-06'), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    private function pay(Booking $booking, PaymentType $type): void
    {
        $engine = app(PaymentEngine::class);
        $payment = $engine->createPayment($booking->refresh(), $type);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $engine->syncFromProvider($payment);
    }

    private function checkInMails(Booking $booking): int
    {
        $this->artisan('mails:send-scheduled')->assertSuccessful();

        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Checkin && $mail->booking->is($booking))->count();
    }

    public function test_with_only_the_down_payment_paid_no_check_in_mail_and_no_door_code(): void
    {
        $booking = $this->booking();
        $this->pay($booking, PaymentType::DownPayment);
        $booking->refresh();

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertTrue($booking->owesBalance());
        $this->assertSame(0, $this->checkInMails($booking));
        $this->assertNull($this->property->accessCodeFor($booking));

        // The balance comes in: the mail goes out on the next run, with the code.
        $this->pay($booking, PaymentType::Balance);
        $booking->refresh();

        $this->assertFalse($booking->owesBalance());
        $this->assertSame(1, $this->checkInMails($booking));
        $this->assertSame('4711', $this->property->accessCodeFor($booking));
    }

    public function test_a_payment_that_was_returned_closes_the_door_again(): void
    {
        $booking = $this->booking();
        $this->pay($booking, PaymentType::DownPayment);
        $this->pay($booking, PaymentType::Balance);
        $this->assertSame('4711', $this->property->accessCodeFor($booking->refresh()));

        // The owner (or the bank) gives the balance back before arrival.
        $balance = $booking->payments()->where('type', PaymentType::Balance->value)->sole();
        app(PaymentEngine::class)->refund($balance);

        $this->assertTrue($booking->refresh()->owesBalance());
        $this->assertNull($this->property->accessCodeFor($booking));
        $this->assertSame(0, $this->checkInMails($booking));
    }

    /** Paid outside the site (cash, bank transfer): the owner confirmed it, so that is the owner's call. */
    public function test_a_booking_the_owner_confirmed_by_hand_gets_the_mail_as_before(): void
    {
        $booking = $this->booking();
        app(BookingEngine::class)->transition($booking, BookingStatus::Confirmed);

        $this->assertFalse($booking->refresh()->owesBalance());
        $this->assertSame(1, $this->checkInMails($booking));
        $this->assertSame('4711', $this->property->accessCodeFor($booking));
    }
}
