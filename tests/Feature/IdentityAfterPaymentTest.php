<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Enums\IdentityStatus;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\AdminNotificationMail;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An accommodation can let guests verify their identity after paying,
 * before a deadline ahead of arrival: a reminder before it, and once it has
 * passed the booking is cancelled (with the chosen refund) or the owner is
 * notified. The check-in email waits for the check.
 */
class IdentityAfterPaymentTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Arrival on 2 March: deadline 7 days before (23 February), reminder 3 days before that (20 February).
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');
        Setting::set('company', 'email', 'owner@example.com');
    }

    private function accommodation(array $extra = []): Property
    {
        static $counter = 0;
        $counter++;

        return Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'id_required' => true, 'id_moment' => Property::ID_AFTER_PAYMENT,
            'id_deadline_days' => 7, 'id_reminder_days' => 3, ...$extra,
        ]);
    }

    /** Booked and the down payment paid. */
    private function paidBooking(Property $accommodation): Booking
    {
        $booking = app(BookingEngine::class)->create(
            $accommodation,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $engine = app(PaymentEngine::class);
        $payment = $engine->createPayment($booking, PaymentType::DownPayment);
        $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        $engine->syncFromProvider($payment);

        return $booking->refresh();
    }

    private function mailed(Booking $booking, MailType $type): int
    {
        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($booking) && $mail->type === $type)->count();
    }

    private function process(string $day): void
    {
        $this->travelTo($day.' 08:45:00');
        $this->artisan('identity:process')->assertSuccessful();
    }

    public function test_the_guest_pays_first_and_the_confirmation_asks_for_the_check_with_its_date(): void
    {
        $accommodation = $this->accommodation();

        // Booking on the site: straight to the payment, not to the ID check.
        $this->post("/{$accommodation->slug}/book", [
            'arrival' => '2026-03-02', 'departure' => '2026-03-05', 'guests' => 2,
            'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1',
        ])->assertRedirect(Booking::sole()->payments()->sole()->checkout_url);

        $booking = $this->paidBooking($this->accommodation(['id_overdue_action' => Property::ID_OVERDUE_CANCEL]));
        $confirmation = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($booking) && $mail->type === MailType::Confirmation)->sole();
        $html = $confirmation->locale('en')->render();

        $this->assertStringContainsString(e(URL::signedRoute('identity.start', $booking)), $html);
        $this->assertStringContainsString('Please do this by Monday 23 February 2026 at the latest.', $html);
        $this->assertStringContainsString('Without it, your booking will be cancelled.', $html);
    }

    public function test_a_payment_link_is_not_held_up_by_the_check_after_the_payment(): void
    {
        $booking = $this->paidBooking($this->accommodation());

        $this->get($booking->paymentLink(PaymentType::Balance))->assertRedirect($booking->payments()->latest('id')->first()->checkout_url);
        $this->assertSame(PaymentType::Balance, $booking->payments()->latest('id')->first()->type);
    }

    public function test_a_reminder_goes_out_once_from_the_set_number_of_days_before_the_deadline(): void
    {
        $booking = $this->paidBooking($this->accommodation());

        $this->process('2026-02-19');
        $this->assertSame(0, $this->mailed($booking, MailType::IdentityReminder));

        $this->process('2026-02-20');
        $this->process('2026-02-21');
        $this->assertSame(1, $this->mailed($booking, MailType::IdentityReminder));

        $reminder = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::IdentityReminder)->sole();
        $this->assertStringContainsString('Please do this by Monday 23 February 2026 at the latest.', $reminder->locale('en')->render());
    }

    public function test_no_reminder_when_set_to_zero_or_when_already_verified(): void
    {
        $noReminder = $this->paidBooking($this->accommodation(['id_reminder_days' => 0]));
        $verified = $this->paidBooking($this->accommodation());
        $verified->update(['identity_status' => IdentityStatus::Verified]);

        $this->process('2026-02-22');
        $this->process('2026-02-24');

        $this->assertSame(0, $this->mailed($noReminder, MailType::IdentityReminder));
        $this->assertSame(0, $this->mailed($verified, MailType::IdentityReminder));
        $this->assertSame(BookingStatus::Confirmed, $verified->refresh()->status);
        $this->assertNull($verified->identity_overdue_at);
    }

    public function test_past_the_deadline_the_owner_is_notified_once_and_the_booking_stays(): void
    {
        $booking = $this->paidBooking($this->accommodation(['id_overdue_action' => Property::ID_OVERDUE_NOTIFY]));

        // The deadline day itself still counts.
        $this->process('2026-02-23');
        Mail::assertNotSent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === AdminNotification::IdentityMissing);

        $this->process('2026-02-24');
        $this->process('2026-02-25');

        $notice = Mail::sent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === AdminNotification::IdentityMissing)->sole();
        $this->assertTrue($notice->hasTo('owner@example.com'));
        $this->assertStringContainsString('You decide whether the booking goes ahead.', $notice->locale('en')->render());

        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertNotNull($booking->identity_overdue_at);
        $this->assertSame([], $this->provider->refunds);
    }

    public function test_cancelled_automatically_with_everything_refunded(): void
    {
        $booking = $this->paidBooking($this->accommodation([
            'id_overdue_action' => Property::ID_OVERDUE_CANCEL, 'id_cancel_refund' => Property::ID_REFUND_FULL,
            'deposit_cents' => 25000, 'deposit_moment' => Property::DEPOSIT_AT_BOOKING,
        ]));
        $paid = $booking->payments()->sole();

        $this->process('2026-02-24');

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
        // Deposit and stay each refunded on their own.
        $this->assertEqualsCanonicalizing(
            [25000, $paid->amount_cents - 25000],
            array_column($this->provider->refunds, 'amount_cents'),
        );

        $this->assertSame(1, $this->mailed($booking, MailType::IdentityCancelled));
        $cancelled = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::IdentityCancelled)->sole();
        $this->assertStringContainsString('We are refunding everything you paid.', $cancelled->locale('en')->render());

        // The owner's cancellation notice says why.
        $notice = Mail::sent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === AdminNotification::Cancellation)->sole();
        $this->assertStringContainsString('Cancelled automatically: the guest did not verify their identity in time.', $notice->locale('en')->render());

        // Once.
        $this->process('2026-02-25');
        $this->assertCount(2, $this->provider->refunds);
        $this->assertSame(1, $this->mailed($booking, MailType::IdentityCancelled));
    }

    public function test_cancelled_with_only_the_deposit_or_no_refund_at_all(): void
    {
        $depositOnly = $this->paidBooking($this->accommodation([
            'id_overdue_action' => Property::ID_OVERDUE_CANCEL, 'id_cancel_refund' => Property::ID_REFUND_DEPOSIT,
            'deposit_cents' => 25000, 'deposit_moment' => Property::DEPOSIT_AT_BOOKING,
        ]));
        $manual = $this->paidBooking($this->accommodation([
            'id_overdue_action' => Property::ID_OVERDUE_CANCEL, 'id_cancel_refund' => Property::ID_REFUND_MANUAL,
            'deposit_cents' => 25000, 'deposit_moment' => Property::DEPOSIT_AT_BOOKING,
        ]));

        $this->process('2026-02-24');

        $this->assertSame(BookingStatus::Cancelled, $depositOnly->refresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $manual->refresh()->status);
        $this->assertSame([25000], array_column($this->provider->refunds, 'amount_cents'));
        $this->assertSame($depositOnly->payments()->where('type', '!=', PaymentType::Refund->value)->sole()->provider_payment_id, $this->provider->refunds[0]['payment']);

        $manualMail = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($manual) && $mail->type === MailType::IdentityCancelled)->sole();
        $this->assertStringContainsString('We will contact you about any refund.', $manualMail->locale('en')->render());
    }

    /** A verifier that answers like Stripe: the outcome of a started session, or an error. */
    private function stripeSays(?IdentityStatus $outcome, bool $unreachable = false): void
    {
        $this->app->instance(\App\Services\IdentityVerifier::class, new class($outcome, $unreachable) implements \App\Services\IdentityVerifier
        {
            public function __construct(private ?IdentityStatus $outcome, private bool $unreachable) {}

            public function start(Booking $booking, string $returnUrl): string
            {
                return 'https://verify.fake.test';
            }

            public function refresh(Booking $booking): void
            {
                if ($this->unreachable) {
                    throw new \RuntimeException('Stripe is not reachable');
                }

                $booking->update(['identity_status' => $this->outcome]);
            }
        });
    }

    public function test_a_check_done_without_coming_back_is_picked_up_from_stripe_first(): void
    {
        // The guest verified and closed the page; the webhook never arrived.
        $booking = $this->paidBooking($this->accommodation(['id_overdue_action' => Property::ID_OVERDUE_CANCEL, 'id_cancel_refund' => Property::ID_REFUND_FULL]));
        $booking->update(['identity_session_id' => 'vs_123', 'identity_status' => IdentityStatus::Pending]);
        $this->stripeSays(IdentityStatus::Verified);

        $this->process('2026-02-21');
        $this->process('2026-02-24');

        $this->assertSame(IdentityStatus::Verified, $booking->refresh()->identity_status);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(0, $this->mailed($booking, MailType::IdentityReminder));
        $this->assertSame([], $this->provider->refunds);
    }

    public function test_when_stripe_cannot_be_reached_the_booking_waits_a_day(): void
    {
        $booking = $this->paidBooking($this->accommodation(['id_overdue_action' => Property::ID_OVERDUE_CANCEL]));
        $booking->update(['identity_session_id' => 'vs_123', 'identity_status' => IdentityStatus::Pending]);

        $this->stripeSays(null, unreachable: true);
        $this->process('2026-02-24');
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertNull($booking->identity_overdue_at);

        // The next day Stripe answers: still not done, so now it is cancelled.
        $this->stripeSays(IdentityStatus::Pending);
        $this->process('2026-02-25');
        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_without_a_started_check_stripe_is_not_asked(): void
    {
        $booking = $this->paidBooking($this->accommodation(['id_overdue_action' => Property::ID_OVERDUE_CANCEL]));
        $this->stripeSays(null, unreachable: true);

        $this->process('2026-02-24');

        $this->assertSame(BookingStatus::Cancelled, $booking->refresh()->status);
    }

    public function test_the_check_in_email_waits_for_the_check(): void
    {
        $booking = $this->paidBooking($this->accommodation(['checkin_days_before_arrival' => 3]));

        $this->travelTo('2026-02-27 09:00:00');
        $this->artisan('mails:send-scheduled')->assertSuccessful();
        $this->assertSame(0, $this->mailed($booking, MailType::Checkin));

        $booking->update(['identity_status' => IdentityStatus::Verified]);
        $this->artisan('mails:send-scheduled')->assertSuccessful();
        $this->assertSame(1, $this->mailed($booking, MailType::Checkin));
    }

    public function test_the_check_in_email_goes_as_before_without_the_check(): void
    {
        $booking = $this->paidBooking($this->accommodation(['id_required' => false, 'checkin_days_before_arrival' => 3]));

        $this->travelTo('2026-02-27 09:00:00');
        $this->artisan('mails:send-scheduled')->assertSuccessful();

        $this->assertSame(1, $this->mailed($booking, MailType::Checkin));
    }

    public function test_the_settings_show_up_step_by_step_and_save(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        $accommodation = Property::create([
            'name' => 'Duinhuis', 'slug' => 'duinhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000,
        ]);

        // The default has no check: only the yes/no question.
        Livewire::test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertFormFieldHidden('id_moment')
            ->fillForm(['id_required' => '1'])
            ->assertFormFieldVisible('id_moment')
            ->assertFormFieldHidden('id_deadline_days')
            ->fillForm(['id_moment' => Property::ID_AFTER_PAYMENT])
            ->assertFormFieldVisible('id_deadline_days')
            ->assertFormFieldVisible('id_reminder_days')
            ->assertFormFieldVisible('id_overdue_action')
            ->assertFormFieldHidden('id_cancel_refund')
            ->fillForm(['id_overdue_action' => Property::ID_OVERDUE_CANCEL])
            ->assertFormFieldVisible('id_cancel_refund')
            ->fillForm(['id_deadline_days' => 10, 'id_cancel_refund' => Property::ID_REFUND_DEPOSIT])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertTrue($accommodation->idAfterPayment());
        $this->assertSame(10, $accommodation->idDeadlineDays());
        $this->assertTrue($accommodation->idOverdueCancels());
        $this->assertSame(Property::ID_REFUND_DEPOSIT, $accommodation->idCancelRefund());
        // Not filled in: follows the default.
        $this->assertNull($accommodation->id_reminder_days);
        $this->assertSame(3, $accommodation->idReminderDays());

        // On the defaults screen the choices are required and saved too.
        Livewire::test(EditDefaults::class)
            ->fillForm(['id_required' => '1', 'id_moment' => Property::ID_AFTER_PAYMENT, 'id_overdue_action' => Property::ID_OVERDUE_NOTIFY])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Property::master()->fresh()->idAfterPayment());
    }
}
