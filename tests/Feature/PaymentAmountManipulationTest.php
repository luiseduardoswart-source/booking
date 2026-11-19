<?php

namespace Tests\Feature;

use App\Engines\PaymentEngine;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Can a guest decide how much they pay? No: the server calculates every amount,
 * and the server asks the payment service itself whether payment was made.
 * These tests try anyway.
 */
class PaymentAmountManipulationTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);

        Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, // €100 per night
        ]);
    }

    /** Book via the form, with extra fields an attacker might add. */
    private function bookWith(array $extra): Booking
    {
        $this->post('/strandhuis/book', [
            'arrival' => today()->addDays(40)->toDateString(),
            'departure' => today()->addDays(43)->toDateString(), // 3 nights = €300
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            ...$extra,
        ])->assertSessionHasNoErrors();

        return Booking::query()->sole();
    }

    public function test_a_sent_along_amount_is_ignored(): void
    {
        $booking = $this->bookWith([
            'total_cents' => 100, 'amount_cents' => 100, 'down_payment_cents' => 100,
            'amount' => '1.00', 'price' => 1, 'total' => 1, 'currency' => 'JPY',
            'price_snapshot' => ['lines' => [], 'total_cents' => 100],
            'down_payment_percentage' => 1, 'base_price_cents' => 1,
        ]);

        $this->assertSame(30000, $booking->total_cents);
        $this->assertSame(9000, $booking->down_payment_cents); // 30% down payment
        $this->assertSame('EUR', $booking->currency);
        $this->assertSame(30000, $booking->price_snapshot['total_cents']);

        // The payment sent to the payment service has the server's amount.
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $this->assertSame(9000, $paymentRecord->amount_cents);

        // And the accommodation itself was not changed.
        $this->assertSame(10000, Property::query()->sole()->base_price_cents);
    }

    public function test_on_a_payment_link_falls_nothing_to_change(): void
    {
        $booking = $this->bookWith([]); // the form already creates the down payment
        $before = Payment::query()->count();
        $link = URL::signedRoute('booking.pay', [$booking, PaymentType::DownPayment->value]);

        // Appending an amount or switching the payment type breaks the signature.
        $this->get($link.'&amount=1')->assertForbidden();
        $this->get($link.'&amount_cents=100')->assertForbidden();
        $this->get(str_replace('/down_payment', '/deposit', $link))->assertForbidden();

        $this->assertSame($before, Payment::query()->count());

        // The real link uses a payment of the correct amount.
        $this->get($link)->assertRedirect();
        $this->assertSame([9000], Payment::query()->pluck('amount_cents')->unique()->values()->all());
    }

    public function test_a_forged_webhook_marks_nothing_as_paid(): void
    {
        $booking = $this->bookWith([]);
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $paymentRecord->update(['provider' => 'stripe']);

        // Stripe event claiming that payment was made: the server fetches the
        // status itself, and according to the payment service nothing was paid.
        $this->postJson('/webhooks/stripe', ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => $paymentRecord->provider_payment_id, 'payment_status' => 'paid', 'amount_total' => 100,
            'metadata' => ['payment_id' => $paymentRecord->id],
        ]]])->assertOk();

        $paymentRecord->update(['provider' => 'mollie']);
        $this->post('/webhooks/mollie', ['id' => $paymentRecord->provider_payment_id, 'status' => 'paid'])->assertOk();

        $this->assertSame(PaymentTransactionStatus::Open, $paymentRecord->fresh()->status);
        $this->assertSame(0, (int) $booking->fresh()->payments()->where('status', PaymentTransactionStatus::Paid->value)->count());
    }

    public function test_the_return_page_trusts_only_the_payment_service(): void
    {
        $booking = $this->bookWith([]);
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        // Returning with "paid" in the URL changes nothing (and breaks the signature).
        $this->get($paymentRecord->returnUrl().'&status=paid&paid=1')->assertForbidden();
        $this->get($paymentRecord->returnUrl())->assertOk();
        $this->assertSame(PaymentTransactionStatus::Open, $paymentRecord->fresh()->status);

        // Only once the payment service says it was paid.
        $this->provider->setStatus($paymentRecord->provider_payment_id, ProviderPaymentStatus::Paid);
        $this->get($paymentRecord->returnUrl())->assertOk();
        $this->assertSame(PaymentTransactionStatus::Paid, $paymentRecord->fresh()->status);
    }

    public function test_the_return_page_is_only_with_the_signed_link_to_see(): void
    {
        $booking = $this->bookWith([]);
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        // By changing the number you no longer see other payments.
        $this->get('/payment/'.$paymentRecord->id)->assertForbidden();
        $this->get('/payment/'.($paymentRecord->id + 1))->assertNotFound();

        $this->get($paymentRecord->returnUrl())->assertOk()->assertSee($booking->booking_number);
    }

    public function test_a_deviating_amount_at_the_payment_service_counts_not_as_paid(): void
    {
        $deviating = new class extends FakeProvider
        {
            public function amountIsCorrect(Payment $payment): bool
            {
                return false;
            }
        };
        $this->app->instance(PaymentProvider::class, $deviating);

        $booking = $this->bookWith([]);
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $deviating->setStatus($paymentRecord->provider_payment_id, ProviderPaymentStatus::Paid);

        Log::shouldReceive('critical')->once()->withArgs(fn (string $notification, array $context) => $context['payment_id'] === $paymentRecord->id);

        app(PaymentEngine::class)->syncFromProvider($paymentRecord);

        $this->assertSame(PaymentTransactionStatus::Open, $paymentRecord->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $booking->fresh()->payment_status);
    }

    public function test_the_test_payment_page_exists_not_with_a_real_payment_service(): void
    {
        $booking = $this->bookWith([]);
        $paymentRecord = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        Setting::set('payments', 'provider', 'mollie');

        $this->get(URL::signedRoute('payment.test', $paymentRecord))->assertNotFound();
        $this->get(URL::signedRoute('payment.test.process', [$paymentRecord, 'paid']))->assertNotFound();
        $this->assertSame(PaymentTransactionStatus::Open, $paymentRecord->fresh()->status);
    }
}
