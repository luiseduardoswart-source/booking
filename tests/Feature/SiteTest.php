<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\MakesSignature;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use MakesSignature, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The fixed dates in these tests (early March 2026) are in the future
        // as seen from this date; arriving in the past is not possible.
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Duinhuisje Zee',
            'slug' => 'duinhuisje-sea',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 12000,
            'cleaning_fee_cents' => 5000,
        ], $overrides));
    }

    public function test_homepage_shows_accommodations(): void
    {
        $this->makeProperty();
        $this->makeProperty(['name' => 'Tweede Huis', 'slug' => 'second-house']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Duinhuisje Zee')
            ->assertSee('Tweede Huis');
    }

    public function test_homepage_shows_only_active_accommodations(): void
    {
        $this->makeProperty();
        $this->makeProperty(['name' => 'Tweede Huis', 'slug' => 'second-house']);
        $this->makeProperty(['name' => 'Verborgen Huis', 'slug' => 'hidden', 'active' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Duinhuisje Zee')
            ->assertDontSee('Verborgen Huis');
    }

    public function test_old_overview_url_redirects_to_homepage_with_filters(): void
    {
        $this->get('/accommodations?arrival=2026-03-02&departure=2026-03-05&guests=2')
            ->assertRedirect(route('home', ['arrival' => '2026-03-02', 'departure' => '2026-03-05', 'guests' => 2]));
    }

    public function test_search_on_the_homepage_filters_on_availability(): void
    {
        $occupied = $this->makeProperty(['name' => 'Bezet Huis', 'slug' => 'occupied-house']);
        $free = $this->makeProperty(['name' => 'Vrij Huis', 'slug' => 'free-house']);

        $this->post("/{$occupied->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ]);

        $this->get('/?arrival=2026-03-03&departure=2026-03-06&guests=2')
            ->assertOk()
            ->assertSee('Vrij Huis')
            ->assertDontSee('Bezet Huis');
    }

    public function test_main_domain_directly_shows_the_accommodation_if_there_is_only_one(): void
    {
        $this->makeProperty();

        // No redirect: the accommodation itself is at "/".
        $this->withSession(['locale' => 'nl'])
            ->get('/')
            ->assertOk()
            ->assertSee('Duinhuisje Zee')
            ->assertSee('Reserveer');
    }

    public function test_direct_loading_of_a_single_accommodation_can_be_switched_off_in_the_settings(): void
    {
        Setting::set('site', 'single_accommodation_direct', '0');
        $this->makeProperty();

        $this->withSession(['locale' => 'nl'])
            ->get('/')
            ->assertOk()
            ->assertSee('Zoeken')
            ->assertDontSee('Reserveer');
    }

    public function test_old_accommodation_url_redirects_permanently(): void
    {
        $property = $this->makeProperty();

        $this->get("/accommodation/{$property->slug}?arrival=2026-03-02")
            ->assertStatus(301)
            ->assertRedirect(route('properties.show', [
                'property' => $property->slug,
                'arrival' => '2026-03-02',
            ]));
    }

    public function test_search_bar_goes_to_homepage_with_multiple_accommodations(): void
    {
        $this->makeProperty();
        $this->makeProperty(['name' => 'Tweede Huis', 'slug' => 'second-house']);

        $this->get('/')
            ->assertOk()
            ->assertSee('action="'.route('home').'"', false);
    }

    public function test_detail_page_renders(): void
    {
        $property = $this->makeProperty();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Duinhuisje Zee')
            ->assertSee('Reserveer');
    }

    public function test_language_switch_to_english(): void
    {
        $property = $this->makeProperty();

        $this->get('/language/en')->assertRedirect();

        $this->withSession(['locale' => 'en'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Reserve')
            ->assertSee('Holiday home');

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Vakantiehuis');
    }

    public function test_inactive_accommodation_gives_404(): void
    {
        $property = $this->makeProperty(['active' => false]);

        $this->get("/{$property->slug}")->assertNotFound();
    }

    public function test_quote_endpoint_gives_price(): void
    {
        $property = $this->makeProperty();

        $this->getJson("/api/properties/{$property->id}/quote?arrival=2026-03-02&departure=2026-03-05&guests=2")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('quote.total_cents', 41000); // 3 × €120 + €50
    }

    public function test_quote_endpoint_reports_unavailability(): void
    {
        $property = $this->makeProperty();
        $property->bookingRules()->create(['min_nights' => 7]);

        $this->getJson("/api/properties/{$property->id}/quote?arrival=2026-03-02&departure=2026-03-05&guests=2")
            ->assertOk()
            ->assertJsonPath('available', false);
    }

    public function test_booking_page_shows_summary(): void
    {
        $property = $this->makeProperty();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}/book?arrival=2026-03-02&departure=2026-03-05&guests=2")
            ->assertOk()
            ->assertSee('Boeking afronden')
            ->assertSee('410,00', false); // 3 × €120 + €50 cleaning
    }

    public function test_full_booking_flow_creates_booking_and_forwards_to_checkout(): void
    {
        $property = $this->makeProperty();

        $response = $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ]);

        $booking = Booking::first();
        $this->assertNotNull($booking);
        $this->assertSame(BookingStatus::New, $booking->status);
        $this->assertSame(1, $booking->payments()->count());

        $response->assertRedirect($booking->payments()->first()->checkout_url);
    }

    public function test_booking_without_agreement_to_the_terms_is_rejected(): void
    {
        $property = $this->makeProperty();

        $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            // no 'terms'
        ])->assertSessionHasErrors('terms');

        $this->assertSame(0, Booking::count());
    }

    public function test_acceptance_is_recorded_with_snapshot(): void
    {
        Storage::fake('local');
        $property = $this->makeProperty([
            'rental_terms' => 'De huurder is aansprakelijk voor schade tijdens het verblijf.',
            'house_rules' => 'Niet roken.',
        ]);

        // There are rental terms, so the guest must sign them too.
        $this->withSession(['locale' => 'nl'])->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            'signature' => $this->signature(),
        ]);

        $booking = Booking::first();
        $this->assertNotNull($booking->terms_accepted_at);
        $this->assertStringContainsString('aansprakelijk voor schade', $booking->terms_snapshot);
        $this->assertStringContainsString('Niet roken.', $booking->terms_snapshot);
    }

    public function test_cancellation_terms_are_on_the_detail_page(): void
    {
        $property = $this->makeProperty(['cancellation_policy' => 'strict']);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Annuleringsvoorwaarden')
            ->assertSee('Volledige terugbetaling tot 14 dagen vóór check-in.');
    }

    public function test_own_cancellation_terms_are_shown(): void
    {
        $property = $this->makeProperty([
            'cancellation_policy' => 'custom',
            'cancellation_policy_text' => "Annuleren kan alleen telefonisch.\nGeen restitutie in het hoogseizoen.",
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Annuleren kan alleen telefonisch.')
            ->assertSee('Geen restitutie in het hoogseizoen.');
    }

    public function test_share_modal_is_on_the_detail_page(): void
    {
        $property = $this->makeProperty();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Deze ruimte delen')
            ->assertSee('Link kopiëren')
            ->assertSee('wa.me/?text=', false)
            ->assertSee('facebook.com/sharer', false)
            ->assertSee('twitter.com/intent/tweet', false)
            ->assertSee('data-share-embed', false);
    }

    public function test_host_is_shown_on_the_detail_page(): void
    {
        $host = \App\Models\Host::create(['name' => 'Familie Jansen', 'bio' => 'Wij wonen om de hoek.']);
        $property = $this->makeProperty();
        $property->hosts()->attach($host);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Gehost door Familie Jansen')
            ->assertSee('Wij wonen om de hoek.');
    }

    public function test_own_highlights_replace_the_automatic_ones(): void
    {
        $property = $this->makeProperty([
            'check_in_from' => '15:00',
            'highlights' => [
                ['icon' => 'pool', 'title' => 'PrivéswimmingPool', 'text' => 'Verwarmd van mei t/m september'],
                ['icon' => 'sprout', 'title' => 'Grote tuin'],
            ],
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('PrivéswimmingPool')
            ->assertSee('Verwarmd van mei t/m september')
            ->assertSee('Grote tuin')
            ->assertDontSee('Zelf inchecken'); // automatic block has been replaced
    }

    public function test_guest_distribution_is_saved_and_determines_the_guest_count(): void
    {
        $property = $this->makeProperty();

        $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 1, // is overwritten by the distribution
            'adults' => 2,
            'children' => 1,
            'infants' => 1,
            'pets' => 0,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ]);

        $booking = Booking::first();
        $this->assertSame(3, $booking->guests); // 2 adults + 1 child; infant does not count
        $this->assertSame(2, $booking->adults);
        $this->assertSame(1, $booking->children);
        $this->assertSame(1, $booking->infants);
        $this->assertSame(0, $booking->pets);
    }

    public function test_double_booking_via_the_site_is_rejected(): void
    {
        $property = $this->makeProperty();

        $details = [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ];

        $this->post("/{$property->slug}/book", $details);
        $this->post("/{$property->slug}/book", $details)
            ->assertSessionHasErrors('availability');

        $this->assertSame(1, Booking::count());
    }

    public function test_confirmation_page_via_signed_url(): void
    {
        $property = $this->makeProperty();

        $this->post("/{$property->slug}/book", [
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
        ]);

        $booking = Booking::first();

        $this->get(URL::signedRoute('bookings.confirmation', $booking))
            ->assertOk()
            ->assertSee($booking->booking_number);

        $this->get("/booking/{$booking->id}/confirmation")->assertForbidden();
    }

    public function test_contact_form_sends_mail(): void
    {
        Setting::set('company', 'email', 'owner@example.com');

        $this->get('/contact')->assertOk();

        $this->post('/contact', [
            'name' => 'Piet',
            'email' => 'piet@example.com',
            'message' => 'Is het huisje in juli nog vrij?',
        ])->assertSessionHas('contact_sent');

        Mail::assertSent(\App\Mail\ContactMessageMail::class);
    }

    public function test_honeypot_blocks_spam(): void
    {
        $this->post('/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'spam',
            'website' => 'filled-in-through-bot',
        ])->assertSessionHas('contact_sent');

        Mail::assertNothingSent();
    }
}
