<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Engines\PricingEngine;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');
    }

    private function makeBookingWithPayment(): array
    {
        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::today()->addDays(30),
            CarbonImmutable::today()->addDays(33),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);

        return [$booking, $payment];
    }

    public function test_payments_overview_renders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get('/admin/payments')
            ->assertOk();
    }

    public function test_return_page_syncs_and_shows_thanks(): void
    {
        [, $payment] = $this->makeBookingWithPayment();
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        // Via the signed link with which the payment service sends the guest back.
        $this->get($payment->returnUrl())
            ->assertOk()
            ->assertSee('Thank you, your payment has been received!');

        $this->assertSame(PaymentTransactionStatus::Paid, $payment->refresh()->status);
    }

    public function test_return_page_follows_the_visitor_language(): void
    {
        [$booking, $payment] = $this->makeBookingWithPayment();
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        $this->withSession(['locale' => 'nl'])
            ->get($payment->returnUrl())
            ->assertOk()
            ->assertSee('<html lang="nl">', false)
            ->assertSee('Bedankt, je betaling is ontvangen!')
            ->assertSee($booking->booking_number);
    }

    public function test_mollie_webhook_processed_payment(): void
    {
        [, $payment] = $this->makeBookingWithPayment();
        $payment->update(['provider' => 'mollie']);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        $this->post('/webhooks/mollie', ['id' => $payment->provider_payment_id])
            ->assertOk();

        $this->assertSame(PaymentTransactionStatus::Paid, $payment->refresh()->status);
    }

    public function test_webhook_without_id_gives_400(): void
    {
        $this->post('/webhooks/mollie')->assertStatus(400);
    }

    public function test_webhook_with_unknown_id_gives_200(): void
    {
        $this->post('/webhooks/mollie', ['id' => 'tr_exists_not'])->assertOk();
    }

    public function test_one_sender_cannot_make_the_site_hammer_the_payment_provider(): void
    {
        for ($i = 0; $i < \App\Http\Controllers\PaymentWebhookController::PER_MINUTE; $i++) {
            $this->postJson('/webhooks/stripe', ['data' => ['object' => ['id' => 'cs_'.$i]]])->assertOk();
        }

        $this->postJson('/webhooks/stripe', ['data' => ['object' => ['id' => 'cs_more']]])->assertStatus(429);
        $this->post('/webhooks/mollie', ['id' => 'tr_more'])->assertStatus(429);

        // Another sender (the provider) still gets through.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/webhooks/mollie', ['id' => 'tr_exists_not'])->assertOk();
    }

    /**
     * Closed with `php artisan down` to test before going live: the payment
     * providers must still reach the site, or refunds and disputes of those
     * test bookings never arrive.
     */
    public function test_webhooks_arrive_while_the_site_is_closed(): void
    {
        [, $payment] = $this->makeBookingWithPayment();
        $payment->update(['provider' => 'mollie']);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);

        $this->app->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, new \Illuminate\Foundation\CacheBasedMaintenanceMode(app('cache'), 'array', 'site-closed'));
        $this->app->maintenanceMode()->activate([]);

        $this->get('/')->assertStatus(503);
        $this->post('/webhooks/mollie', ['id' => $payment->provider_payment_id])->assertOk();
        $this->assertSame(PaymentTransactionStatus::Paid, $payment->refresh()->status);
        $this->postJson('/webhooks/stripe', ['type' => 'charge.refunded'])->assertStatus(200);
    }
}
