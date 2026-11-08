<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\MailType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * One valid payment link per payment: never two links side by side with which
 * the guest could pay twice, and a link that keeps working after the payment
 * page at the provider has expired.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    private PaymentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        $this->engine = app(PaymentEngine::class);
    }

    private function booking(array $fields = []): Booking
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        return Booking::create($fields + [
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-03', 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    private function pay(Payment $payment): void
    {
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $this->engine->syncFromProvider($payment);
    }

    private function refund(Payment $payment): void
    {
        $this->engine->refund($payment->refresh());
    }

    private function screen(): Testable
    {
        return Livewire::actingAs($this->owner())->test(ManagePayments::class);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_a_second_request_gives_same_link(): void
    {
        $booking = $this->booking();

        $first = $this->engine->createPayment($booking, PaymentType::Balance);
        $second = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertTrue($second->is($first));
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, $booking->payments()->count());
    }

    public function test_a_other_kind_payment_gets_a_own_link(): void
    {
        $booking = $this->booking();

        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $deposit = $this->engine->createPayment($booking, PaymentType::Deposit);

        $this->assertFalse($deposit->is($downPayment));
    }

    public function test_a_expired_link_is_replace(): void
    {
        $booking = $this->booking();
        $old = $this->engine->createPayment($booking, PaymentType::Balance);
        $this->provider->setStatus($old->provider_payment_id, ProviderPaymentStatus::Expired);

        $new = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertFalse($new->is($old));
        $this->assertSame(PaymentTransactionStatus::Expired, $old->refresh()->status);
    }

    public function test_a_link_with_a_other_amount_is_not_reused(): void
    {
        $booking = $this->booking();
        $old = $this->engine->createPayment($booking, PaymentType::Balance);
        $booking->update(['total_cents' => 40000]);

        $new = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertFalse($new->is($old));
        $this->assertSame(40000, $new->amount_cents);
        $this->assertSame(PaymentTransactionStatus::Cancelled, $old->refresh()->status);
        $this->assertSame(ProviderPaymentStatus::Canceled, $this->provider->status($old));
    }

    public function test_a_balance_link_lets_the_open_down_payment_link_expire(): void
    {
        $booking = $this->booking();
        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $balance = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertSame(32000, $balance->amount_cents);
        $this->assertSame(PaymentTransactionStatus::Cancelled, $downPayment->refresh()->status);
        $this->assertSame([$balance->id], $booking->openPayments()->pluck('id')->all());
    }

    public function test_a_down_payment_link_lets_the_open_balance_link_expire(): void
    {
        $booking = $this->booking();
        $balance = $this->engine->createPayment($booking, PaymentType::Balance);

        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $this->assertSame(PaymentTransactionStatus::Cancelled, $balance->refresh()->status);
        $this->assertSame([$downPayment->id], $booking->openPayments()->pluck('id')->all());
    }

    public function test_a_open_deposit_link_stays_next_to_a_stay_link_exist(): void
    {
        $booking = $this->booking();
        $deposit = $this->engine->createPayment($booking, PaymentType::Deposit);

        $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertSame(PaymentTransactionStatus::Open, $deposit->refresh()->status);
    }

    public function test_succeeds_expire_not_then_comes_there_after_all_a_new_link_and_stays_the_old_open(): void
    {
        $booking = $this->booking();
        $old = $this->engine->createPayment($booking, PaymentType::DownPayment);

        $rejecting = new class extends FakeProvider
        {
            public function cancel(Payment $payment): void
            {
                throw new RuntimeException('This payment cannot be canceled');
            }
        };
        $this->app->instance(PaymentProvider::class, $rejecting);
        $this->app->forgetInstance(PaymentEngine::class);

        $new = app(PaymentEngine::class)->createPayment($booking, PaymentType::Balance);

        $this->assertFalse($new->is($old));
        $this->assertSame(PaymentTransactionStatus::Open, $old->refresh()->status);
    }

    public function test_cancel_lets_all_open_links_expire(): void
    {
        $booking = $this->booking();
        $balance = $this->engine->createPayment($booking, PaymentType::Balance);
        $deposit = $this->engine->createPayment($booking, PaymentType::Deposit);

        app(BookingEngine::class)->cancel($booking);

        $this->assertSame(PaymentTransactionStatus::Cancelled, $balance->refresh()->status);
        $this->assertSame(PaymentTransactionStatus::Cancelled, $deposit->refresh()->status);
        $this->assertTrue($booking->openPayments()->isEmpty());
    }

    public function test_a_paid_payment_stays_are_at_cancel(): void
    {
        $booking = $this->booking();
        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($downPayment);

        app(BookingEngine::class)->cancel($booking->refresh());

        $this->assertSame(PaymentTransactionStatus::Paid, $downPayment->refresh()->status);
    }

    public function test_a_cancelled_booking_gets_no_payment(): void
    {
        $booking = $this->booking();
        $link = $booking->paymentLink(PaymentType::Balance);
        $this->get($link)->assertRedirect();
        $this->assertSame(1, $booking->payments()->count());

        app(BookingEngine::class)->cancel($booking);

        // The link still exists in the guest's mail, but leads nowhere any more.
        $this->get($link)->assertRedirect($booking->payments()->first()->returnUrl());
        $this->assertSame(1, $booking->payments()->count());

        $this->expectException(InvalidArgumentException::class);
        $this->engine->createPayment($booking->refresh(), PaymentType::Balance);
    }

    public function test_a_cancelled_booking_without_payments_gives_not_found(): void
    {
        $booking = $this->booking(['status' => 'cancelled']);

        $this->get($booking->paymentLink(PaymentType::Balance))->assertNotFound();
        $this->assertSame(0, $booking->payments()->count());
    }

    public function test_a_meanwhile_paid_link_delivers_no_new_payment_on(): void
    {
        $booking = $this->booking();
        $balance = $this->engine->createPayment($booking, PaymentType::Balance);
        // The payment succeeded, but the webhook has not (yet) arrived.
        $this->provider->setStatus($balance->provider_payment_id, ProviderPaymentStatus::Paid);

        try {
            $this->engine->createPayment($booking, PaymentType::Balance);
            $this->fail('No new payment should have been created.');
        } catch (InvalidArgumentException) {
            $this->assertSame(PaymentTransactionStatus::Paid, $balance->refresh()->status);
            $this->assertSame(1, $booking->payments()->count());
        }
    }

    public function test_a_link_that_the_provider_not_knows_is_a_new_not_in_the_gone(): void
    {
        $booking = $this->booking();
        $this->engine->createPayment($booking, PaymentType::Balance);

        // For example after switching from a test key to a live key.
        $rejecting = new class extends FakeProvider
        {
            public function status(Payment $payment): ProviderPaymentStatus
            {
                if ($payment->id === 1) {
                    throw new RuntimeException('No such checkout.session');
                }

                return parent::status($payment);
            }
        };
        $this->app->instance(PaymentProvider::class, $rejecting);
        $this->app->forgetInstance(PaymentEngine::class);
        $engine = app(PaymentEngine::class);

        $this->assertNotSame(1, $engine->createPayment($booking, PaymentType::Balance)->id);
    }

    public function test_a_open_link_of_a_other_provider_is_not_asked_or_reused(): void
    {
        $booking = $this->booking();
        $mollie = Payment::create([
            'booking_id' => $booking->id, 'type' => PaymentType::Balance,
            'status' => PaymentTransactionStatus::Open, 'amount_cents' => 32000,
            'provider' => 'mollie', 'provider_payment_id' => 'tr_1', 'checkout_url' => 'https://mollie.test/1',
        ]);

        $new = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->assertFalse($new->is($mollie));
        $this->assertSame(PaymentTransactionStatus::Open, $mollie->refresh()->status);
    }

    public function test_a_paid_down_payment_can_not_again_unless_he_is_refunded(): void
    {
        $booking = $this->booking();
        $downPayment = $this->engine->createPayment($booking, PaymentType::DownPayment);
        $this->pay($downPayment);

        try {
            $this->engine->createPayment($booking->refresh(), PaymentType::DownPayment);
            $this->fail('A paid down payment must not be possible a second time.');
        } catch (InvalidArgumentException) {
            $this->refund($downPayment);
        }

        $this->assertSame(9600, $this->engine->createPayment($booking->refresh(), PaymentType::DownPayment)->amount_cents);
    }

    public function test_a_paid_deposit_can_not_again_unless_he_is_refunded(): void
    {
        $booking = $this->booking();
        $deposit = $this->engine->createPayment($booking, PaymentType::Deposit);
        $this->pay($deposit);

        try {
            $this->engine->createPayment($booking->refresh(), PaymentType::Deposit);
            $this->fail('A paid deposit must not be possible a second time.');
        } catch (InvalidArgumentException) {
            $this->refund($deposit);
        }

        $this->assertSame(5000, $this->engine->createPayment($booking->refresh(), PaymentType::Deposit)->amount_cents);
    }

    public function test_the_fixed_link_goes_twice_to_same_checkout(): void
    {
        $booking = $this->booking();
        $link = $booking->paymentLink(PaymentType::Balance);

        $first = $this->get($link)->assertRedirect()->headers->get('Location');
        $second = $this->get($link)->assertRedirect()->headers->get('Location');

        $this->assertSame($first, $second);
        $this->assertSame(1, $booking->payments()->count());
    }

    public function test_the_fixed_link_works_still_as_the_checkout_expired_is(): void
    {
        $booking = $this->booking();
        $link = $booking->paymentLink(PaymentType::Balance);
        $old = $this->engine->createPayment($booking, PaymentType::Balance);
        $this->provider->setStatus($old->provider_payment_id, ProviderPaymentStatus::Expired);

        $new = $booking->payments()->latest('id');

        $this->get($link)->assertRedirect();
        $this->assertNotSame($old->id, $new->first()->id);
        $this->assertSame(PaymentTransactionStatus::Open, $new->first()->status);
    }

    public function test_the_fixed_link_works_not_without_signature(): void
    {
        $booking = $this->booking();

        $this->get(route('booking.pay', ['booking' => $booking->id, 'type' => 'balance']))->assertForbidden();
        $this->assertSame(0, $booking->payments()->count());
    }

    public function test_the_notification_contains_the_fixed_link_and_a_copy_button(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->owner())
            ->test(ListBookings::class)
            ->callAction(TestAction::make('payment_link')->table($booking), ['type' => 'balance']);

        // The way the browser receives it: the button must survive the trip through the session.
        app(NotificationMailbox::class)->flush();
        $notifications = new Notifications;
        $notifications->mount();
        $notifications->pullNotificationsFromSession();

        $notification = $notifications->notifications->last()->toArray();
        $button = $notification['actions'][0];

        $this->assertStringContainsString($booking->paymentLink(PaymentType::Balance), $notification['body']);
        $this->assertSame('copy', $button['name']);
        $this->assertStringContainsString('copyText', $button['alpineClickHandler']);
        Mail::assertNothingQueued();
    }

    public function test_the_switch_mails_the_link_immediately_along(): void
    {
        $booking = $this->booking();

        Livewire::actingAs($this->owner())
            ->test(ListBookings::class)
            ->callAction(TestAction::make('payment_link')->table($booking), ['type' => 'balance', 'mail' => true]);

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::PaymentRequest);
    }

    public function test_at_payments_are_the_buttons_only_at_a_open_link(): void
    {
        $booking = $this->booking();
        $open = $this->engine->createPayment($booking, PaymentType::Balance);
        $paid = $this->engine->createPayment($booking, PaymentType::Deposit);
        $this->pay($paid);

        $this->screen()
            ->assertActionVisible(TestAction::make('copy')->table($open))
            ->assertActionVisible(TestAction::make('mail')->table($open))
            ->assertActionHidden(TestAction::make('copy')->table($paid))
            ->assertActionHidden(TestAction::make('mail')->table($paid));
    }

    public function test_mail_goes_in_the_language_of_the_guest_with_the_fixed_link(): void
    {
        $booking = $this->booking(['locale' => 'es']);
        $payment = $this->engine->createPayment($booking, PaymentType::Balance);

        $this->screen()->callAction(TestAction::make('mail')->table($payment));

        Mail::assertQueued(BookingMail::class, function (BookingMail $mail) use ($booking) {
            $html = $mail->render();

            return $mail->type === MailType::PaymentRequest
                && $mail->locale === 'es'
                && $mail->hasTo('jan@example.com')
                && str_contains($html, 'Pagar ahora')
                && str_contains($html, e($booking->paymentLink(PaymentType::Balance)))
                && str_contains($mail->withLocale('es', fn () => $mail->envelope()->subject), 'Solicitud de pago');
        });
        $this->assertDatabaseHas('sent_mails', [
            'booking_id' => $booking->id,
            'mail_type' => MailType::PaymentRequest->value,
            'status' => 'sent',
        ]);
    }

    public function test_only_view_may_not_mail(): void
    {
        $payment = $this->engine->createPayment($this->booking(), PaymentType::Balance);
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'permissions' => ['payments' => ['view']],
        ]);

        Livewire::actingAs($staff)
            ->test(ManagePayments::class)
            ->assertActionVisible(TestAction::make('copy')->table($payment))
            ->assertActionHidden(TestAction::make('mail')->table($payment));
    }

    public function test_the_booking_shows_the_open_link(): void
    {
        $booking = $this->booking();
        $payment = $this->engine->createPayment($booking, PaymentType::Balance);

        Livewire::actingAs($this->owner())
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertSee(__('Open payment links'))
            ->assertSee(e($booking->paymentLink(PaymentType::Balance)), false);

        $this->pay($payment);

        Livewire::actingAs($this->owner())
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertDontSee(__('Open payment links'));
    }

    public function test_mail_from_the_booking_sends_the_mail(): void
    {
        $booking = $this->booking();
        $payment = $this->engine->createPayment($booking, PaymentType::Balance);

        Livewire::actingAs($this->owner())
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction(TestAction::make("mail_{$payment->id}")->schemaComponent("payment_link_{$payment->id}", 'form'));

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::PaymentRequest
            && $mail->payment->is($payment));
    }
}
