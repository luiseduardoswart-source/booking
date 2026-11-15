<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\IdentityStatus;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Services\IdentityVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FakeIdentityVerifier implements IdentityVerifier
{
    public IdentityStatus $outcome = IdentityStatus::Pending;

    public function start(Booking $booking, string $returnUrl): string
    {
        $booking->update([
            'identity_session_id' => 'vs_fake_'.$booking->id,
            'identity_status' => IdentityStatus::Pending,
        ]);

        return 'https://verify.fake.test/session/'.$booking->id;
    }

    public function refresh(Booking $booking): void
    {
        $booking->update(['identity_status' => $this->outcome]);
    }
}

class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeIdentityVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        // The fixed dates in these tests (early March 2026) are in the future
        // as seen from this date; arriving in the past is not possible.
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
        $this->verifier = new FakeIdentityVerifier;
        $this->app->instance(IdentityVerifier::class, $this->verifier);
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');
        Property::master()->update(['id_required' => true]);
    }

    private function makeBooking(): Booking
    {
        $property = Property::create([
            'name' => 'Verificatiehuis',
            'slug' => 'verificatiehuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        return app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    public function test_verification_link_start_a_session_and_sends_through(): void
    {
        $booking = $this->makeBooking();

        $this->get(URL::signedRoute('identity.start', $booking))
            ->assertRedirect('https://verify.fake.test/session/'.$booking->id);

        $booking->refresh();
        $this->assertSame(IdentityStatus::Pending, $booking->identity_status);
        $this->assertSame('vs_fake_'.$booking->id, $booking->identity_session_id);
    }

    public function test_verification_requires_valid_signature_and_active_setting(): void
    {
        $booking = $this->makeBooking();

        $this->get("/booking/{$booking->id}/verification")->assertForbidden();

        Property::master()->update(['id_required' => false]);
        $this->get(URL::signedRoute('identity.start', $booking))->assertNotFound();
    }

    public function test_return_page_sends_after_successful_verification_through_to_the_payment(): void
    {
        $booking = $this->makeBooking();
        $this->get(URL::signedRoute('identity.start', $booking));

        $this->verifier->outcome = IdentityStatus::Verified;

        $response = $this->withSession(['locale' => 'nl'])
            ->get(URL::signedRoute('identity.return', $booking));

        $booking->refresh();
        $this->assertSame(IdentityStatus::Verified, $booking->identity_status);
        $response->assertRedirect($booking->payments()->first()->checkout_url);
    }

    public function test_return_page_shows_status_as_long_as_verification_still_not_succeeded_is(): void
    {
        $booking = $this->makeBooking();
        $this->get(URL::signedRoute('identity.start', $booking));

        $this->withSession(['locale' => 'nl'])
            ->get(URL::signedRoute('identity.return', $booking))
            ->assertOk()
            ->assertSee('Verificatie in behandeling');
    }

    public function test_stripe_webhook_processed_identity_events(): void
    {
        $booking = $this->makeBooking();
        $this->get(URL::signedRoute('identity.start', $booking));

        $this->verifier->outcome = IdentityStatus::Verified;

        $this->post('/webhooks/stripe', [
            'type' => 'identity.verification_session.verified',
            'data' => ['object' => ['id' => 'vs_fake_'.$booking->id]],
        ])->assertOk();

        $this->assertSame(IdentityStatus::Verified, $booking->refresh()->identity_status);
    }

    public function test_stripe_webhook_makes_payment_link_and_mails_that_as_the_guest_not_returns(): void
    {
        $booking = $this->makeBooking();
        $this->get(URL::signedRoute('identity.start', $booking)); // session started, guest leaves the site

        $this->verifier->outcome = IdentityStatus::Verified;

        $this->post('/webhooks/stripe', [
            'type' => 'identity.verification_session.verified',
            'data' => ['object' => ['id' => 'vs_fake_'.$booking->id]],
        ])->assertOk();

        $booking->refresh();
        $this->assertSame(1, $booking->payments()->count());

        Mail::assertQueued(BookingMail::class, fn (BookingMail $mail) => $mail->booking->is($booking) && $mail->type === MailType::Verified);
    }

    public function test_booking_flow_sends_at_required_verification_first_to_identity_check(): void
    {
        $property = Property::create([
            'name' => 'Verificatiehuis 2',
            'slug' => 'verificatiehuis-2-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        $response = $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ]);

        $booking = Booking::where('property_id', $property->id)->first();
        $this->assertNotNull($booking);
        $this->assertSame(0, $booking->payments()->count());
        $response->assertRedirect(URL::signedRoute('identity.start', $booking));
    }

    public function test_confirmation_mail_contains_verification_button_as_the_setting_on_is(): void
    {
        $booking = $this->makeBooking();

        $mail = new BookingMail($booking, MailType::Confirmation);
        $this->assertStringContainsString('verification', $mail->render());

        // Disabled → no verification block.
        Property::master()->update(['id_required' => false]);
        $mailWithout = new BookingMail($booking->refresh(), MailType::Confirmation);
        $this->assertStringNotContainsString('/verification', $mailWithout->render());
    }

    public function test_after_a_failed_check_the_guest_can_try_again_from_the_page(): void
    {
        $booking = $this->makeBooking();
        $this->get(URL::signedRoute('identity.start', $booking));
        $this->verifier->outcome = IdentityStatus::Failed;

        // No "link in your confirmation email": that email only comes after the payment.
        $this->get(URL::signedRoute('identity.return', $booking))
            ->assertOk()
            ->assertSee(__('Verification failed'))
            ->assertDontSee('confirmation email')
            ->assertSee(e(URL::signedRoute('identity.start', $booking)), false)
            ->assertSee(__('Try again'));

        // That button starts a new check.
        $this->verifier->outcome = IdentityStatus::Pending;
        $this->get(URL::signedRoute('identity.start', $booking))
            ->assertRedirect('https://verify.fake.test/session/'.$booking->id);
    }

    public function test_a_payment_link_first_asks_for_a_missing_id_check_and_then_pays_what_it_was_for(): void
    {
        $booking = $this->makeBooking();
        $payLink = $booking->paymentLink(PaymentType::Balance);

        // Not verified yet: first the check, which remembers what was to be paid.
        $toCheck = URL::signedRoute('identity.start', ['booking' => $booking, 'pay' => PaymentType::Balance->value]);
        $this->get($payLink)->assertRedirect($toCheck);
        $this->assertSame(0, $booking->payments()->count());

        $this->get($toCheck)->assertRedirect('https://verify.fake.test/session/'.$booking->id);

        // Verified: back to that same payment, not to the down payment.
        $this->verifier->outcome = IdentityStatus::Verified;
        $this->get(URL::signedRoute('identity.return', ['booking' => $booking, 'pay' => PaymentType::Balance->value]))
            ->assertRedirect($payLink);

        $this->get($payLink)->assertRedirect();
        $this->assertSame(PaymentType::Balance, $booking->payments()->sole()->type);
    }

    public function test_the_check_remembers_the_payment_through_the_return_from_stripe(): void
    {
        $verifier = new class extends FakeIdentityVerifier
        {
            public ?string $returnUrl = null;

            public function start(Booking $booking, string $returnUrl): string
            {
                $this->returnUrl = $returnUrl;

                return parent::start($booking, $returnUrl);
            }
        };
        $this->app->instance(IdentityVerifier::class, $verifier);
        $booking = $this->makeBooking();

        $this->get(URL::signedRoute('identity.start', ['booking' => $booking, 'pay' => PaymentType::Deposit->value]));

        $this->assertSame(URL::signedRoute('identity.return', ['booking' => $booking, 'pay' => PaymentType::Deposit->value]), $verifier->returnUrl);
    }

    public function test_a_payment_link_pays_straight_away_once_verified_or_without_the_check(): void
    {
        $verified = $this->makeBooking();
        $verified->update(['identity_status' => IdentityStatus::Verified]);
        $this->get($verified->paymentLink(PaymentType::DownPayment))->assertRedirect($verified->payments()->sole()->checkout_url);

        Property::master()->update(['id_required' => false]);
        $without = $this->makeBooking();
        $this->get($without->paymentLink(PaymentType::DownPayment))->assertRedirect($without->payments()->sole()->checkout_url);
    }

    public function test_the_setting_asks_plainly_whether_guests_verify_their_identity(): void
    {
        \Livewire\Livewire::actingAs(\App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]));

        // Yes or no: not "after booking", because it happens before the payment.
        \Livewire\Livewire::test(\App\Filament\Resources\Properties\Pages\EditDefaults::class)
            ->assertSee(__('Ask guests to verify their identity'))
            ->assertDontSee('verify their identity after booking');
    }
}
