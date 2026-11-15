<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Mail\BookingMail;
use App\Mail\GuestLoginMail;
use App\Models\Booking;
use App\Models\GuestLogin;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use App\Support\GuestAccess;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "My bookings": guests see their bookings and documents without a password,
 * through a one-time login link by email or the link in every guest email.
 */
class GuestBookingsTest extends TestCase
{
    use RefreshDatabase;

    private FakeProvider $provider;

    private Property $accommodation;

    /** Each booking of a test a week later, so they never overlap. */
    private int $weeks = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
        $this->provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $this->provider);
        Setting::set('payments', 'provider', 'fake');

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function booking(string $email = 'jan@example.com', bool $paid = true): Booking
    {
        $week = $this->weeks++;

        $booking = app(BookingEngine::class)->create(
            $this->accommodation,
            CarbonImmutable::parse('2026-03-02')->addWeeks($week),
            CarbonImmutable::parse('2026-03-05')->addWeeks($week),
            2,
            ['name' => 'Jan Jansen', 'email' => $email],
        );

        if ($paid) {
            $engine = app(PaymentEngine::class);
            $payment = $engine->createPayment($booking, PaymentType::DownPayment);
            $this->provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
            $engine->syncFromProvider($payment);
        }

        return $booking->refresh();
    }

    /** Asks for a link and returns the code from the email. */
    private function askLink(string $email): ?string
    {
        $this->post(route('guest.link'), ['email' => $email])->assertRedirect(route('guest.bookings'));

        return Mail::sent(GuestLoginMail::class)->last()?->token;
    }

    /** Logs in through the link; returns the cookie value the browser keeps. */
    private function logIn(string $email): string
    {
        $token = $this->askLink($email);
        $response = $this->post(route('guest.login.use', ['token' => $token]))->assertRedirect(route('guest.bookings'));

        return $response->getCookie(GuestAccess::COOKIE)->getValue();
    }

    /** A new visit: what the session held is gone, only cookies sent along count. */
    private function newVisit(): void
    {
        $session = $this->app['session']->driver();
        $session->getHandler()->destroy($session->getId());
        $session->flush();
    }

    public function test_the_link_goes_only_to_a_known_address_and_the_answer_is_always_the_same(): void
    {
        $this->booking('Jan@Example.com');

        $this->askLink('nobody@example.com');
        $unknown = session('guest_status');
        Mail::assertNotSent(GuestLoginMail::class);

        $this->askLink('JAN@example.com');
        $this->assertSame($unknown, session('guest_status'));
        Mail::assertSent(GuestLoginMail::class, fn (GuestLoginMail $mail) => $mail->hasTo('jan@example.com'));

        // No address in the link: only a random code.
        $this->assertStringNotContainsString('example.com', route('guest.login', ['token' => Mail::sent(GuestLoginMail::class)->sole()->token]));
    }

    public function test_at_most_three_links_per_address_per_ten_minutes(): void
    {
        $this->booking();

        foreach (range(1, 5) as $attempt) {
            $this->askLink('jan@example.com');
        }

        Mail::assertSent(GuestLoginMail::class, 3);
    }

    public function test_opening_the_link_does_not_use_it_only_the_button_does_and_only_once(): void
    {
        $mine = $this->booking();
        $someoneElses = $this->booking('piet@example.com');
        $token = $this->askLink('jan@example.com');

        // A mail program that opens the link to scan it does not use it up.
        $this->get(route('guest.login', ['token' => $token]))->assertOk()->assertSee(__('Log in'));
        $this->assertSame(0, GuestLogin::query()->where('kind', GuestLogin::SESSION)->count());

        $this->post(route('guest.login.use', ['token' => $token]))->assertRedirect(route('guest.bookings'));

        $this->get(route('guest.bookings'))
            ->assertSee($mine->booking_number)
            ->assertDontSee($someoneElses->booking_number)
            ->assertSee(__('Logged in as :email', ['email' => 'jan@example.com']));

        // Once.
        $this->newVisit();
        $this->post(route('guest.login.use', ['token' => $token]))->assertSessionHas('guest_error');
    }

    public function test_the_link_expires_after_fifteen_minutes(): void
    {
        $this->booking();
        $token = $this->askLink('jan@example.com');

        $this->travel(16)->minutes();

        $this->post(route('guest.login.use', ['token' => $token]))->assertSessionHas('guest_error');
        $this->assertNull(GuestAccess::email(request()));
    }

    public function test_the_browser_stays_logged_in_and_logging_out_ends_it_for_real(): void
    {
        $booking = $this->booking();
        $cookie = $this->logIn('jan@example.com');

        // A new visit (session gone): the cookie still works, for 90 days.
        $this->newVisit();
        $this->withCookie(GuestAccess::COOKIE, $cookie)->get(route('guest.bookings'))->assertSee($booking->booking_number);

        $this->travel(91)->days();
        $this->newVisit();
        $this->withCookie(GuestAccess::COOKIE, $cookie)->get(route('guest.bookings'))->assertDontSee($booking->booking_number);
        $this->travelBack();

        // Logging out deletes the login: a copied cookie no longer works.
        $cookie = $this->logIn('jan@example.com');
        $this->withCookie(GuestAccess::COOKIE, $cookie)->post(route('guest.logout'))->assertRedirect(route('guest.bookings'));
        $this->newVisit();
        $this->withCookie(GuestAccess::COOKIE, $cookie)->get(route('guest.bookings'))->assertDontSee($booking->booking_number);
    }

    public function test_log_out_on_all_devices(): void
    {
        $booking = $this->booking();
        $phone = $this->logIn('jan@example.com');
        $this->newVisit();
        $laptop = $this->logIn('jan@example.com');

        $this->withCookie(GuestAccess::COOKIE, $laptop)->post(route('guest.logout'), ['everywhere' => 1]);

        $this->newVisit();
        $this->withCookie(GuestAccess::COOKIE, $phone)->get(route('guest.bookings'))->assertDontSee($booking->booking_number);
        $this->assertSame(0, GuestLogin::count());
    }

    public function test_the_link_in_an_email_opens_that_booking_until_three_months_after_departure(): void
    {
        $booking = $this->booking();
        $url = GuestAccess::bookingUrl($booking);

        // Every guest email carries it.
        $html = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Confirmation)->sole()->locale('en')->render();
        $this->assertStringContainsString(e($url), $html);

        $this->get($url)->assertOk()->assertSee($booking->booking_number)->assertSee(__('Booking confirmation'));

        // Without the link and without logging in: not.
        $this->newVisit();
        $this->get(route('guest.booking', $booking))->assertRedirect(route('guest.bookings'))->assertSessionHas('guest_error');

        // Departure 5 March: still on 5 June, no longer on 6 June.
        $this->travelTo('2026-06-05 23:00:00');
        $this->get($url)->assertOk();
        $this->newVisit();
        $this->travelTo('2026-06-06 00:30:00');
        $this->get($url)->assertRedirect(route('guest.bookings'));
    }

    public function test_a_logged_in_guest_sees_only_their_own_bookings(): void
    {
        $this->booking('jan@example.com');
        $someoneElses = $this->booking('piet@example.com');
        $this->logIn('JAN@example.com');

        $this->get(route('guest.booking', $someoneElses))->assertRedirect(route('guest.bookings'));
        $this->get(route('guest.document', ['booking' => $someoneElses->id, 'kind' => 'confirmation']))->assertForbidden();
    }

    public function test_the_documents_download_as_pdf(): void
    {
        $booking = $this->booking();
        $payment = $booking->payments()->sole();
        $this->get(GuestAccess::bookingUrl($booking))
            ->assertSee(__('Booking confirmation'))
            ->assertSee(__('Invoice'))
            ->assertSee(__('Payment receipt'));

        foreach ([
            ['kind' => 'confirmation'],
            ['kind' => 'invoice'],
            ['kind' => 'receipt', 'payment' => $payment->id],
        ] as $document) {
            $response = $this->get(route('guest.document', ['booking' => $booking->id, ...$document]))->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }

        // A receipt of another booking through this booking: not found.
        $other = $this->booking();
        $this->get(route('guest.document', ['booking' => $booking->id, 'kind' => 'receipt', 'payment' => $other->payments()->sole()->id]))->assertNotFound();
    }

    public function test_an_unpaid_booking_has_no_documents_yet_and_offers_to_pay(): void
    {
        $booking = $this->booking(paid: false);

        $this->get(GuestAccess::bookingUrl($booking))
            ->assertOk()
            ->assertSee(__('Your documents appear here once your booking has been confirmed.'))
            ->assertSee(e($booking->paymentLink(PaymentType::DownPayment)), false);

        $this->get(route('guest.document', ['booking' => $booking->id, 'kind' => 'confirmation']))->assertNotFound();
        $this->get(route('guest.document', ['booking' => $booking->id, 'kind' => 'invoice']))->assertNotFound();
        $this->assertSame(0, \App\Models\Invoice::count());
    }

    public function test_the_owner_can_log_a_guest_out_everywhere(): void
    {
        $booking = $this->booking();
        $this->logIn('jan@example.com');
        $this->assertSame(1, GuestLogin::query()->where('kind', GuestLogin::SESSION)->count());

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        Livewire::test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->callAction(TestAction::make('guest_logout'));

        $this->assertSame(0, GuestLogin::count());
    }

    public function test_my_bookings_is_an_icon_at_the_top_right_and_reserved_as_an_address(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        [$header, $footer] = explode('<footer', $html, 2);

        // In the header, after the language picker, as an icon with its name for screen readers.
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('guest.bookings'), '#').'" title="'.preg_quote(__('My bookings'), '#').'" aria-label="'.preg_quote(__('My bookings'), '#').'" data-my-bookings#', $header);
        $this->assertGreaterThan(strpos($header, route('locale.switch', 'en')), strpos($header, 'data-my-bookings'));
        // No longer in the footer.
        $this->assertStringNotContainsString(route('guest.bookings'), $footer);

        $this->assertContains('my-bookings', \App\Support\Slugs::RESERVED);
    }
}
