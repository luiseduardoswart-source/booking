<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class TestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');
    }

    private function makePayment()
    {
        $property = Property::create([
            'name' => 'Testhuis', 'slug' => 'testhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        return app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
    }

    public function test_checkout_url_points_to_the_test_page(): void
    {
        $payment = $this->makePayment();

        $this->assertStringStartsWith(route('payment.test', $payment).'?signature=', $payment->checkout_url);

        $this->get($payment->checkout_url)
            ->assertOk()
            ->assertSee(__('Test mode'))
            ->assertSee($payment->booking->booking_number);
    }

    public function test_payment_succeeded_is_processed(): void
    {
        $payment = $this->makePayment();

        $this->get(URL::signedRoute('payment.test.process', [$payment, 'paid']))
            ->assertRedirect($payment->returnUrl());

        // The return page syncs with the provider and processes the payment.
        $this->get($payment->returnUrl())->assertOk();

        $this->assertSame(PaymentTransactionStatus::Paid, $payment->fresh()->status);
    }

    public function test_failed_payment_is_processed(): void
    {
        $payment = $this->makePayment();

        $this->get(URL::signedRoute('payment.test.process', [$payment, 'failed']));
        $this->get($payment->returnUrl())->assertOk();

        $this->assertSame(PaymentTransactionStatus::Failed, $payment->fresh()->status);
    }

    public function test_test_page_exists_not_with_a_real_provider(): void
    {
        $payment = $this->makePayment();

        // As soon as a real provider is chosen, the fake checkout is gone —
        // otherwise someone could mark a payment as paid.
        Setting::set('payments', 'provider', 'mollie');

        $this->get(URL::signedRoute('payment.test', $payment))->assertNotFound();
        $this->get(URL::signedRoute('payment.test.process', [$payment, 'paid']))->assertNotFound();

        $this->assertSame(PaymentTransactionStatus::Open, $payment->fresh()->status);
    }

    public function test_the_test_page_only_opens_through_its_signed_link(): void
    {
        $payment = $this->makePayment();

        // Otherwise anyone could mark any payment number as paid while the
        // test provider is on.
        $this->get(route('payment.test', $payment))->assertForbidden();
        $this->get(route('payment.test.process', [$payment, 'paid']))->assertForbidden();

        $this->assertSame(PaymentTransactionStatus::Open, $payment->fresh()->status);
    }

    public function test_unknown_outcome_is_rejected(): void
    {
        $payment = $this->makePayment();

        $this->get(URL::signedRoute('payment.test.process', [$payment, 'free']))->assertNotFound();
    }
}
