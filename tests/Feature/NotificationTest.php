<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationTest extends TestCase
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

    private function makeBooking(string $arrival = '2026-03-02', string $departure = '2026-03-05'): Booking
    {
        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        return app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse($arrival),
            CarbonImmutable::parse($departure),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    public function test_new_booking_sends_confirmation_mail(): void
    {
        $booking = $this->makeBooking();

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation
            && $mail->booking->is($booking)
            && $mail->hasTo('jan@example.com'));

        $this->assertDatabaseHas('sent_mails', [
            'booking_id' => $booking->id,
            'mail_type' => MailType::Confirmation->value,
            'status' => 'sent',
        ]);
    }

    public function test_payment_sends_payment_received_mail(): void
    {
        $booking = $this->makeBooking();
        $engine = app(PaymentEngine::class);
        $payment = $engine->createPayment($booking, PaymentType::DownPayment);

        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $engine->syncFromProvider($payment);

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::PaymentReceived);
    }

    public function test_scheduled_command_sends_checkin_mail_and_is_idempotent(): void
    {
        $booking = $this->makeBooking(
            today()->addDays(2)->toDateString(),
            today()->addDays(5)->toDateString(),
        );
        $booking->update(['status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Paid]);

        $this->artisan('mails:send-scheduled')->assertSuccessful();
        $this->artisan('mails:send-scheduled')->assertSuccessful();

        Mail::assertQueued(BookingMail::class, function (BookingMail $mail) {
            return $mail->type === MailType::Checkin;
        });

        $this->assertSame(1, \App\Models\SentMail::query()
            ->where('booking_id', $booking->id)
            ->where('mail_type', MailType::Checkin->value)
            ->count());
    }

    public function test_balance_payment_reminder_only_at_down_payment_paid(): void
    {
        $downPaymentPaid = $this->makeBooking(
            today()->addDays(7)->toDateString(),
            today()->addDays(10)->toDateString(),
        );
        $downPaymentPaid->update(['status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::DownPaymentPaid]);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::BalancePayment
            && $mail->booking->is($downPaymentPaid));
    }

    public function test_review_request_after_departure(): void
    {
        $booking = $this->makeBooking(
            today()->subDays(5)->toDateString(),
            today()->subDays(2)->toDateString(),
        );
        $booking->update(['status' => BookingStatus::Completed, 'payment_status' => PaymentStatus::Paid]);

        $this->artisan('mails:send-scheduled')->assertSuccessful();

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Review);
    }

    public function test_payment_link_in_mail_leads_to_checkout(): void
    {
        $booking = $this->makeBooking();

        $url = \Illuminate\Support\Facades\URL::signedRoute('booking.pay', [
            'booking' => $booking->id,
            'type' => 'balance',
        ]);

        $this->get($url)->assertRedirect();
        $this->assertSame(1, $booking->payments()->count());
    }

    public function test_payment_link_without_valid_signature_is_rejected(): void
    {
        $booking = $this->makeBooking();

        $this->get("/booking/{$booking->id}/pay/balance")->assertForbidden();
    }
}
