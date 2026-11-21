<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\AdminNotification;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\AdminNotificationMail;
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

/**
 * A guest can have a payment reversed by the bank (chargeback), and an owner
 * can refund at Mollie or Stripe itself. The provider then still calls the
 * payment "paid". The site asks what went back, books it, and tells the owner.
 */
class PaymentReturnedOutsideTheSiteTest extends TestCase
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
        Setting::set('company', 'email', 'owner@example.com');
        $this->travelTo('2027-03-01 08:00:00');

        // 300 for three nights, paid at once; self check-in with a door code.
        $this->property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
            'base_price_cents' => 10000, 'down_payment_percentage' => 100,
            'check_in_kind' => Property::CHECK_IN_SELF, 'access_code_kind' => Property::ACCESS_FIXED, 'access_code' => '4711',
        ]);
    }

    /** A booking with its one payment, paid. */
    private function paid(): Payment
    {
        $booking = app(BookingEngine::class)->create(
            $this->property, CarbonImmutable::parse('2027-03-10'), CarbonImmutable::parse('2027-03-13'), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        return $this->webhook($payment);
    }

    /** What a webhook of the provider does: ask the provider how the payment stands. */
    private function webhook(Payment $payment): Payment
    {
        return app(PaymentEngine::class)->syncFromProvider($payment->fresh())->fresh();
    }

    private function mailsAboutReturns(): int
    {
        return Mail::sent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === AdminNotification::PaymentReturned
            && $mail->hasTo('owner@example.com'))->count();
    }

    public function test_a_reversed_payment_is_booked_and_the_owner_is_told(): void
    {
        $payment = $this->paid();
        $booking = $payment->booking;
        $this->assertSame(PaymentStatus::Paid, $booking->payment_status);
        $this->assertSame('4711', $this->property->accessCodeFor($booking));

        // The bank takes the whole amount back; the provider calls the webhook.
        $this->provider->returnedOutside[$payment->provider_payment_id] = 30000;
        $this->webhook($payment);

        $booking->refresh();
        $return = $booking->payments()->where('type', PaymentType::Refund->value)->sole();
        $this->assertSame(30000, $return->amount_cents);
        $this->assertSame($payment->id, $return->refunded_payment_id);
        $this->assertSame(30000, $booking->paymentOverview()['outstanding']);
        $this->assertNotSame(PaymentStatus::Paid, $booking->payment_status);

        // No door code for a stay that is not paid any more, and the owner knows.
        $this->assertTrue($booking->owesBalance());
        $this->assertNull($this->property->accessCodeFor($booking));
        $this->assertSame(1, $this->mailsAboutReturns());

        // The provider calls again about the same thing: nothing twice.
        $this->webhook($payment);
        $this->assertSame(1, $booking->payments()->where('type', PaymentType::Refund->value)->count());
        $this->assertSame(1, $this->mailsAboutReturns());
    }

    public function test_a_refund_made_in_the_admin_is_not_counted_again(): void
    {
        $payment = $this->paid();

        // Refunded through the site: the provider reports it, and we already have it.
        app(PaymentEngine::class)->refund($payment, 5000);
        $this->webhook($payment);

        $this->assertSame(1, $payment->booking->payments()->where('type', PaymentType::Refund->value)->count());
        $this->assertSame(5000, $payment->booking->paymentOverview()['refunded']);
        $this->assertSame(0, $this->mailsAboutReturns());

        // On top of that, 100 is refunded at the provider itself.
        $this->provider->returnedOutside[$payment->provider_payment_id] = 10000;
        $this->webhook($payment);

        $this->assertSame(15000, $payment->booking->paymentOverview()['refunded']);
        $this->assertSame(1, $this->mailsAboutReturns());
    }

    public function test_when_the_bank_decides_for_the_owner_the_stay_is_paid_again(): void
    {
        $payment = $this->paid();
        $this->provider->returnedOutside[$payment->provider_payment_id] = 30000;
        $this->webhook($payment);
        $this->assertTrue($payment->booking->fresh()->owesBalance());

        // The dispute is won: the money is back with the owner.
        $this->provider->returnedOutside[$payment->provider_payment_id] = 0;
        $this->webhook($payment);

        $booking = $payment->booking->fresh();
        $this->assertSame(0, $booking->payments()->where('type', PaymentType::Refund->value)->count());
        $this->assertSame(PaymentStatus::Paid, $booking->payment_status);
        $this->assertSame('4711', $this->property->accessCodeFor($booking));
    }

    public function test_a_provider_that_cannot_answer_does_not_break_the_webhook(): void
    {
        $payment = $this->paid();
        $this->app->instance(PaymentProvider::class, new class extends FakeProvider
        {
            public function status(Payment $payment): ProviderPaymentStatus
            {
                return ProviderPaymentStatus::Paid;
            }

            public function returnedCents(Payment $payment): int
            {
                throw new \RuntimeException('The provider is not reachable.');
            }
        });
        $this->app->forgetInstance(PaymentEngine::class);

        $this->webhook($payment);

        $this->assertSame(PaymentStatus::Paid, Booking::sole()->payment_status);
    }
}
