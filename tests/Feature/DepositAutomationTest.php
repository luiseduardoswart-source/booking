<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Mail\BookingMail;
use App\Mail\DepositReminderMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DepositAutomationTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
    }

    private function makeBooking(string $arrival, string $departure, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        $property = Property::create([
            'name' => 'Borghuis',
            'slug' => 'borghuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
            'deposit_cents' => 20000,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $booking->update(['status' => $status]);

        return $booking->refresh();
    }

    private function payDeposit(Booking $booking): Payment
    {
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::Deposit);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);

        return $payment->refresh();
    }

    public function test_deposit_request_is_automatic_emailed_for_arrival(): void
    {
        $booking = $this->makeBooking(
            today()->addDays(5)->toDateString(),
            today()->addDays(8)->toDateString(),
        );

        $this->artisan('mails:send-scheduled')->assertSuccessful();
        $this->artisan('mails:send-scheduled')->assertSuccessful(); // idempotent

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Deposit
            && $mail->booking->is($booking));

        $this->assertSame(1, \App\Models\SentMail::where('mail_type', MailType::Deposit->value)->count());
    }

    public function test_no_deposit_request_as_the_deposit_already_paid_is(): void
    {
        $booking = $this->makeBooking(
            today()->addDays(5)->toDateString(),
            today()->addDays(8)->toDateString(),
        );
        $this->payDeposit($booking);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        Mail::assertNotQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Deposit);
    }

    public function test_deposit_is_automatic_refunded_after_departure(): void
    {
        Property::master()->update(['deposit_auto_refund' => true]);

        $booking = $this->makeBooking(
            today()->subDays(10)->toDateString(),
            today()->subDays(5)->toDateString(),
            BookingStatus::Completed,
        );
        $deposit = $this->payDeposit($booking);

        $this->artisan('deposits:process')->assertSuccessful();

        $this->assertSame(1, $deposit->refunds()->count());
        $this->assertCount(1, $this->provider->refunds);
        $this->assertSame(20000, $this->provider->refunds[0]['amount_cents']);

        // Tweede run doet niets meer.
        $this->artisan('deposits:process')->assertSuccessful();
        $this->assertSame(1, $deposit->refunds()->count());
    }

    public function test_without_automatic_mode_gets_the_administrator_a_reminder(): void
    {
        Property::master()->update(['deposit_auto_refund' => false]);
        Setting::set('company', 'email', 'owner@example.com');

        $booking = $this->makeBooking(
            today()->subDays(10)->toDateString(),
            today()->subDays(5)->toDateString(),
            BookingStatus::Completed,
        );
        $this->payDeposit($booking);

        $this->artisan('deposits:process')->assertSuccessful();

        Mail::assertSent(DepositReminderMail::class, fn (DepositReminderMail $mail) => $mail->deposits->count() === 1
            && $mail->hasTo('owner@example.com'));
    }

    public function test_deposit_of_recent_departed_guest_waits_still(): void
    {
        Property::master()->update(['deposit_auto_refund' => true]);

        // Vertrokken gisteren; standaard wachttijd is 3 dagen.
        $booking = $this->makeBooking(
            today()->subDays(4)->toDateString(),
            today()->subDay()->toDateString(),
            BookingStatus::Completed,
        );
        $deposit = $this->payDeposit($booking);

        $this->artisan('deposits:process')->assertSuccessful();

        $this->assertSame(0, $deposit->refunds()->count());
    }
}
