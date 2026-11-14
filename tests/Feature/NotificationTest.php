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

    private function confirmationsFor(Booking $booking): int
    {
        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation
            && $mail->booking->is($booking))->count();
    }

    private function pay(Booking $booking, PaymentType $type): void
    {
        $engine = app(PaymentEngine::class);
        $payment = $engine->createPayment($booking->refresh(), $type);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $engine->syncFromProvider($payment);
    }

    public function test_the_confirmation_goes_out_once_the_payment_has_succeeded(): void
    {
        $booking = $this->makeBooking();

        // Booked but not paid (and, if required, not verified yet): no confirmation.
        $this->assertSame(0, $this->confirmationsFor($booking));

        $this->pay($booking, PaymentType::DownPayment);

        $this->assertSame(1, $this->confirmationsFor($booking));
        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation
            && $mail->hasTo('jan@example.com'));
        $this->assertDatabaseHas('sent_mails', [
            'booking_id' => $booking->id,
            'mail_type' => MailType::Confirmation->value,
            'status' => 'sent',
        ]);

        // The balance later: no second confirmation.
        $this->pay($booking, PaymentType::Balance);
        $this->assertSame(1, $this->confirmationsFor($booking));
    }

    public function test_with_nothing_to_pay_online_the_confirmation_goes_out_right_away(): void
    {
        // A provider that cannot make a payment: the guest lands on the confirmation page.
        $this->app->instance(PaymentProvider::class, new class extends FakeProvider
        {
            public function create(\App\Models\Payment $payment, string $redirectUrl): \App\Payments\ProviderPaymentResult
            {
                throw new \RuntimeException('No payments possible');
            }
        });
        $booking = $this->makeBooking();

        $url = app(PaymentEngine::class)->checkoutOrConfirmationUrl($booking);

        $this->assertStringContainsString('/confirmation', $url);
        $this->assertSame(1, $this->confirmationsFor($booking));

        // Back on the page again: not twice.
        app(PaymentEngine::class)->checkoutOrConfirmationUrl($booking);
        $this->assertSame(1, $this->confirmationsFor($booking));
    }

    public function test_a_cancelled_booking_gets_no_confirmation(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => BookingStatus::Cancelled]);

        app(PaymentEngine::class)->checkoutOrConfirmationUrl($booking);

        $this->assertSame(0, $this->confirmationsFor($booking));
    }

    public function test_a_booking_made_in_the_admin_still_gets_its_confirmation_at_once(): void
    {
        $property = Property::create([
            'name' => 'Beheerhuis', 'slug' => 'beheerhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);
        \Livewire\Livewire::actingAs(User::factory()->create(['role' => \App\Enums\UserRole::Administrator]));

        \Livewire\Livewire::test(\App\Filament\Resources\Bookings\Pages\CreateBooking::class)
            ->fillForm([
                'property_id' => $property->id,
                'arrival_date' => today()->addDays(10)->toDateString(),
                'departure_date' => today()->addDays(13)->toDateString(),
                'guests' => 2,
                'guest_name' => 'Piet Telefoon',
                'guest_email' => 'piet@example.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::query()->where('guest_email', 'piet@example.com')->firstOrFail();
        $this->assertSame(1, $this->confirmationsFor($booking));
    }

    public function test_the_first_payment_is_in_the_confirmation_and_later_ones_get_their_own_email(): void
    {
        $booking = $this->makeBooking();

        // First payment: one email, the confirmation, that names the payment and carries both PDFs.
        $this->pay($booking, PaymentType::DownPayment);

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation
            && $mail->payment?->type === PaymentType::DownPayment);
        Mail::assertNotQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::PaymentReceived);

        $confirmation = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation)->first();
        $this->assertSame(
            ["confirmation-{$booking->booking_number}.pdf", "payment_receipt-{$booking->booking_number}.pdf"],
            array_map(fn ($attachment) => $attachment->as, $confirmation->attachments()),
        );
        $html = $confirmation->locale('en')->render();
        $this->assertStringContainsString('We have received your payment:', $html);
        $this->assertStringContainsString(\App\Support\Money::format($booking->refresh()->down_payment_cents, $booking->currency), $html);

        // The balance later: "payment received", no second confirmation.
        $this->pay($booking, PaymentType::Balance);

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::PaymentReceived
            && $mail->payment?->type === PaymentType::Balance);
        $this->assertSame(1, $this->confirmationsFor($booking));
    }

    public function test_a_confirmation_without_a_payment_names_none_and_carries_only_its_own_pdf(): void
    {
        $booking = $this->makeBooking();
        $mail = new BookingMail($booking, MailType::Confirmation);

        $this->assertCount(1, $mail->attachments());
        $this->assertStringNotContainsString('We have received your payment:', $mail->locale('en')->render());
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
