<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\MailType;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Support\MailPreview;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The standard text of each automatic email can be looked at from its own
 * text field; the check-out and payment emails read right.
 */
class StandardMailTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    public function test_the_check_out_email_names_the_departure_date_instead_of_tomorrow(): void
    {
        $booking = app(BookingEngine::class)->create(
            $this->accommodation,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $html = (new BookingMail($booking->fresh(), MailType::Checkout))->locale('en')->render();

        $this->assertStringContainsString('ends on Thursday 5 March 2026', $html);
        $this->assertStringNotContainsString('Tomorrow', $html);
    }

    public function test_the_own_text_of_payment_received_comes_right_below_the_greeting(): void
    {
        $html = MailPreview::html($this->accommodation, MailType::PaymentReceived, 'en');

        $greeting = strpos($html, 'Dear '.__('Example guest', [], 'en'));
        $ownText = strpos($html, 'Your own text appears here');
        $received = strpos($html, 'We have received your payment:');

        $this->assertNotFalse($received);
        $this->assertTrue($greeting < $ownText && $ownText < $received);
    }

    public function test_the_check_in_email_puts_address_and_times_on_lines_of_their_own(): void
    {
        $this->accommodation->update([
            'address' => 'Strandweg 1', 'postal_code' => '1234 AB', 'city' => 'Zandvoort',
            'check_in_from' => '15:00', 'check_in_until' => '20:00', 'check_out_until' => '10:30',
        ]);

        $html = MailPreview::html($this->accommodation->fresh(), MailType::Checkin, 'en');

        // Each starts a paragraph of its own, instead of running on after the previous one.
        $this->assertMatchesRegularExpression('#<p[^>]*><strong[^>]*>Address:</strong> Strandweg 1, 1234 AB Zandvoort</p>#', $html);
        $this->assertMatchesRegularExpression('#<p[^>]*><strong[^>]*>Check-in from 15:00</strong> \(until 20:00\)</p>#', $html);
        $this->assertMatchesRegularExpression('#<p[^>]*><strong[^>]*>Check-out: 10:30</strong></p>#', $html);
    }

    public function test_the_confirmation_puts_the_stay_details_on_lines_of_their_own(): void
    {
        $html = MailPreview::html($this->accommodation, MailType::Confirmation, 'en');

        $this->assertMatchesRegularExpression('#Strandhuis</strong><br>\s*Check-in: <strong#', $html);
        $this->assertMatchesRegularExpression('#</strong><br>\s*Check-out: <strong#', $html);
        $this->assertMatchesRegularExpression('#</strong><br>\s*Guests: <strong[^>]*>2</strong>#', $html);
    }

    public function test_every_standard_email_shows_with_a_marker_and_nothing_is_saved(): void
    {
        $this->accommodation->update(['mail_texts' => ['checkin' => ['nl' => '<p>Sleutelkluis 1234</p>']]]);
        app()->setLocale('en');

        foreach (MailType::cases() as $type) {
            $html = MailPreview::html($this->accommodation, $type, 'nl');

            $this->assertSame(1, substr_count($html, 'Hier komt je eigen tekst'), $type->value);
            $this->assertStringContainsString('Voorbeeldgast', $html, $type->value);
        }

        // The admin keeps their own language, the record its own text, and no example is stored.
        $this->assertSame('en', app()->getLocale());
        $this->assertSame('<p>Sleutelkluis 1234</p>', $this->accommodation->mail_texts['checkin']['nl']);
        $this->assertSame('<p>Sleutelkluis 1234</p>', $this->accommodation->fresh()->mail_texts['checkin']['nl']);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Payment::count());
    }

    private function previewUrl(Property $property, string $type, string $locale): string
    {
        return route('filament.admin.mail-preview', ['property' => $property->id, 'type' => $type, 'locale' => $locale]);
    }

    public function test_the_info_button_at_a_text_field_opens_the_standard_email(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        Livewire::test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertSee(__('Show the standard email'))
            ->mountAction(TestAction::make('standard_mail')->schemaComponent('own_texts.mail_texts_nl.mail_texts.checkin.nl'))
            ->assertMountedActionModalSeeHtml('data-standard-mail')
            ->assertMountedActionModalSeeHtml(e($this->previewUrl($this->accommodation, 'checkin', 'nl')));

        Livewire::test(EditDefaults::class)
            ->mountAction(TestAction::make('standard_mail')->schemaComponent('own_texts.mail_texts_en.mail_texts.review.en'))
            ->assertMountedActionModalSeeHtml(e($this->previewUrl(Property::master(), 'review', 'en')));
    }

    public function test_the_preview_is_the_email_with_its_markdown_worked_out(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $html = $this->get($this->previewUrl($this->accommodation, 'checkin', 'nl'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->getContent();

        $this->assertStringContainsString('Hier komt je eigen tekst', $html);
        $this->assertStringNotContainsString('**', $html);
        $this->assertStringNotContainsString('[if BLOCK]', $html);

        $this->get($this->previewUrl(Property::master(), 'review', 'en'))
            ->assertOk()
            ->assertSee('Your own text appears here');
    }

    public function test_the_preview_is_only_for_who_may_edit_the_emails(): void
    {
        $url = $this->previewUrl($this->accommodation, 'checkin', 'nl');

        $this->get($url)->assertRedirect();

        $host = \App\Models\Host::create(['name' => 'Vreemd']);
        $stranger = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id,
            'permissions' => ['accommodations' => \App\Support\Access::ACTIONS, 'payment_rules' => \App\Support\Access::ACTIONS],
        ]);
        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->actingAs($stranger)->get($this->previewUrl(Property::master(), 'checkin', 'nl'))->assertForbidden();
    }

    public function test_an_unknown_email_or_language_is_not_found(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $this->get($this->previewUrl($this->accommodation, 'newsletter', 'nl'))->assertNotFound();
        $this->get($this->previewUrl($this->accommodation, 'checkin', 'xx'))->assertNotFound();
    }
}
