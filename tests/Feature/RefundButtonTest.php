<?php

namespace Tests\Feature;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentResult;
use App\Payments\ProviderPaymentStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The Refund button on Payments: only while something can still be refunded,
 * and an error from the provider does not appear as technical text on screen.
 */
class RefundButtonTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): Payment
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $booking = Booking::create([
            'booking_number' => 'BK-TEST-1', 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'down_payment_paid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 20000, 'down_payment_cents' => 6000, 'price_snapshot' => ['lines' => []],
        ]);

        return Payment::create([
            'booking_id' => $booking->id, 'type' => PaymentType::DownPayment,
            'status' => PaymentTransactionStatus::Paid, 'amount_cents' => 6000,
            'provider' => 'stripe', 'provider_payment_id' => 'cs_test_1', 'paid_at' => now(),
        ]);
    }

    private function screen(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(ManagePayments::class);
    }

    public function test_the_button_disappears_as_everything_refunded_is(): void
    {
        $paymentRecord = $this->payment();

        $this->screen()->assertActionVisible(TestAction::make('refund')->table($paymentRecord));

        Payment::create([
            'booking_id' => $paymentRecord->booking_id, 'type' => PaymentType::Refund,
            'status' => PaymentTransactionStatus::Refunded, 'amount_cents' => 6000,
            'provider' => 'stripe', 'provider_payment_id' => 're_1', 'refunded_payment_id' => $paymentRecord->id,
        ]);

        $this->screen()->assertActionHidden(TestAction::make('refund')->table($paymentRecord));
    }

    public function test_a_rejection_of_the_provider_gives_a_understandable_notification(): void
    {
        $paymentRecord = $this->payment();
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'stripe';
            }

            public function isTestMode(): bool
            {
                return true;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function returnedCents(Payment $payment): int
            {
                return 0;
            }

            public function create(Payment $payment, string $redirectUrl): ProviderPaymentResult
            {
                throw new RuntimeException('not needed');
            }

            public function status(Payment $payment): ProviderPaymentStatus
            {
                return ProviderPaymentStatus::Paid;
            }

            public function refund(Payment $payment, int $amountCents): string
            {
                throw new RuntimeException('Charge ch_123 has already been refunded.');
            }

            public function cancel(Payment $payment): void
            {
                throw new RuntimeException('not needed');
            }

            public function supportsCurrency(string $currency): bool
            {
                return true;
            }

            public function amountIsCorrect(Payment $payment): bool
            {
                return true;
            }
        });

        $this->screen()
            ->callAction(TestAction::make('refund')->table($paymentRecord))
            ->assertNotified(
                Notification::make()
                    ->title(__('Refund failed'))
                    ->body(__('The payment provider declined the refund. Check the provider\'s dashboard to see whether anything has already been refunded.'))
                    ->danger()
            );

        $this->assertSame(0, Payment::where('type', PaymentType::Refund->value)->count());
    }
}
