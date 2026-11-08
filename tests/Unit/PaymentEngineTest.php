<?php

namespace Tests\Unit;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Engines\PricingEngine;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentEngineTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    private PaymentEngine $engine;

    private BookingEngine $bookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeProvider;
        $notifications = new \App\Engines\NotificationEngine;
        $this->bookings = new BookingEngine(new AvailabilityEngine, new PricingEngine, $notifications);
        $this->engine = new PaymentEngine($this->provider, $this->bookings, $notifications);
    }

    private function makeBooking(): Booking
    {

        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
            'deposit_cents' => 20000,
        ]);

        // 3 nights at €100 = €300 total, down payment €90.
        return $this->bookings->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    private function pay(\App\Models\Payment $payment): void
    {
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $this->engine->syncFromProvider($payment);
    }

    public function test_down_payment_create_gives_checkout_url(): void
    {
        $booking = $this->makeBooking();

        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->assertSame(9000, $payment->amount_cents);
        $this->assertSame(PaymentTransactionStatus::Open, $payment->status);
        $this->assertNotNull($payment->checkout_url);
        $this->assertNotNull($payment->provider_payment_id);
    }

    public function test_paid_down_payment_confirms_the_booking(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->pay($payment);

        $booking->refresh();
        $this->assertSame(PaymentStatus::DownPaymentPaid, $booking->payment_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNotNull($payment->refresh()->paid_at);
    }

    public function test_balance_payment_makes_the_booking_full_paid(): void
    {
        $booking = $this->makeBooking();
        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($downPayment);

        $remaining = $this->engine->createPayment($booking->refresh(), PaymentType::Balance);
        $this->assertSame(21000, $remaining->amount_cents); // €300 - €90

        $this->pay($remaining);

        $booking->refresh();
        $this->assertSame(PaymentStatus::Paid, $booking->payment_status);
        $this->assertSame(BookingStatus::Paid, $booking->status);
    }

    public function test_double_webhook_processed_not_double(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->pay($payment);
        $firstPaidAt = $payment->refresh()->paid_at;

        // Same webhook once more.
        $this->engine->syncFromProvider($payment);

        $this->assertEquals($firstPaidAt, $payment->refresh()->paid_at);
        $this->assertSame(1, $booking->payments()->where('type', PaymentType::DownPayment->value)->count());
        $this->assertSame(PaymentStatus::DownPaymentPaid, $booking->refresh()->payment_status);
    }

    public function test_failed_payment_changes_the_booking_not(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Failed);
        $this->engine->syncFromProvider($payment);

        $booking->refresh();
        $this->assertSame(PaymentTransactionStatus::Failed, $payment->refresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $booking->payment_status);
        $this->assertSame(BookingStatus::New, $booking->status);
    }

    public function test_deposit_used_the_deposit_amount(): void
    {
        $booking = $this->makeBooking();

        $payment = $this->engine->createPayment($booking, PaymentType::Deposit);

        $this->assertSame(20000, $payment->amount_cents);
    }

    public function test_full_refund_set_payment_status_on_refunded(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($payment);

        $this->engine->refund($payment->refresh());

        $booking->refresh();
        $this->assertSame(PaymentStatus::Refunded, $booking->payment_status);
        $this->assertCount(1, $this->provider->refunds);
        $this->assertSame(9000, $this->provider->refunds[0]['amount_cents']);
    }

    public function test_a_payment_can_not_two_time_are_refunded(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($payment);
        $this->engine->refund($payment->refresh());

        $this->assertSame(0, $payment->refresh()->remainingRefundableCents());

        try {
            $this->engine->refund($payment);
            $this->fail('A second refund should have been rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('This payment has already been fully refunded.'), $e->getMessage());
        }

        // The provider was called only once.
        $this->assertCount(1, $this->provider->refunds);
    }

    public function test_after_a_partial_refund_can_only_the_rest_back(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($payment);

        $this->engine->refund($payment->refresh(), 3000);
        $this->assertSame(6000, $payment->refresh()->remainingRefundableCents());

        try {
            $this->engine->refund($payment, 6001);
            $this->fail('Refunding more than what is left should have been rejected.');
        } catch (InvalidArgumentException) {
        }

        // Without an amount, exactly the remainder is refunded.
        $this->engine->refund($payment);
        $this->assertSame([3000, 6000], array_column($this->provider->refunds, 'amount_cents'));
        $this->assertSame(PaymentStatus::Refunded, $booking->refresh()->payment_status);
    }

    public function test_deposit_give_back_affects_the_payment_of_the_stay_not(): void
    {
        $booking = $this->makeBooking();
        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($downPayment);
        $deposit = $this->engine->createPayment($booking->refresh(), PaymentType::Deposit);
        $this->pay($deposit);

        $this->engine->refund($deposit->refresh());

        $booking->refresh();
        $this->assertSame(PaymentStatus::DownPaymentPaid, $booking->payment_status);
        $this->assertSame(['paid' => 9000, 'refunded' => 0, 'outstanding' => 21000], $booking->paymentOverview());
        $this->assertSame(21000, $this->engine->remainingAmountCents($booking));
    }

    public function test_open_payment_can_not_are_refunded(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->expectException(InvalidArgumentException::class);

        $this->engine->refund($payment);
    }
}
