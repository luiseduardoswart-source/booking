<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use Filament\Forms\Components\RichEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Placeholders in the custom mail texts: in the editor you type {{ and pick a
 * field, which appears as a label in the text; in the mail the data
 * of the booking appears there.
 */
class MailPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    /** A placeholder as the editor saves it. */
    private function field(string $key): string
    {
        return '<span data-type="mergeTag" data-id="'.$key.'"></span>';
    }

    private function booking(string $text, array $fields = []): Booking
    {
        static $counter = 0;
        $counter++;

        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => "strandhuis-{$counter}", 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'address' => 'Duinweg 1', 'postal_code' => '1234 AB', 'city' => 'Zandvoort',
            'check_in_from' => '15:00', 'check_out_until' => '10:30',
            'mail_texts' => ['confirmation' => ['nl' => $text, 'en' => $text]],
        ]);

        return Booking::create([
            'booking_number' => "BK-INVUL-{$counter}", 'property_id' => $accommodation->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com',
            'arrival_date' => '2026-12-07', 'departure_date' => '2026-12-10', 'guests' => 2,
            'total_cents' => 30000, 'down_payment_cents' => 9000,
            'price_snapshot' => ['lines' => []], 'locale' => 'nl',
            ...$fields,
        ]);
    }

    /** The mail as readable text, without formatting. */
    private function mailText(Booking $booking): string
    {
        $html = (new BookingMail($booking, MailType::Confirmation))->render();

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES));
    }

    public function test_the_placeholders_are_with_the_booking_filled(): void
    {
        Setting::set('company', 'name', 'Kustverhuur');
        Setting::set('company', 'phone', '+31 6 12345678');
        Setting::set('company', 'email', 'info@kustverhuur.test');

        $v = fn (string $key) => $this->field($key);
        $text = $this->mailText($this->booking(
            "<p>Beste {$v('first_name')} ({$v('name')}), boeking {$v('booking_number')} van {$v('accommodation')}.</p>"
            ."<p>Van {$v('arrival')} tot {$v('departure')}, {$v('nights')} nachten met {$v('guests')} gasten.</p>"
            ."<p>Inchecken vanaf {$v('check_in')}, uitchecken voor {$v('check_out')}, op {$v('address')}. Totaal {$v('total')}.</p>"
            ."<p>Bel <strong>{$v('company')}</strong> op {$v('phone')} of mail {$v('email')}.</p>"
        ));

        foreach ([
            'Beste Jan (Jan de Vries), boeking BK-INVUL-', ' van Strandhuis.',
            'Van maandag 7 december 2026 tot donderdag 10 december 2026, 3 nachten met 2 gasten.',
            'Inchecken vanaf 15:00, uitchecken voor 10:30, op Duinweg 1, 1234 AB Zandvoort. Totaal € 300,00.',
            'Bel Kustverhuur op +31 6 12345678 of mail info@kustverhuur.test.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_formatting_off_the_editor_stays_in_the_mail_are(): void
    {
        $html = (new BookingMail($this->booking('<p>Neem je <strong>sleutel</strong> mee.</p><p>Tot snel!</p>'), MailType::Confirmation))->render();

        $this->assertMatchesRegularExpression('/<strong[^>]*>sleutel<\/strong>/', $html);
        // Both paragraphs as HTML, not as plain text with visible tags.
        $this->assertStringNotContainsString('&lt;p&gt;', $html);
        $this->assertStringContainsString('Tot snel!', $html);
    }

    public function test_the_date_is_in_the_language_of_the_guest(): void
    {
        $text = $this->mailText($this->booking("<p>See you on {$this->field('arrival')}.</p>", ['locale' => 'en']));

        $this->assertStringContainsString('See you on Monday 7 December 2026.', $text);
    }

    public function test_the_name_of_the_guest_stays_plain_text(): void
    {
        $booking = $this->booking("<p>Beste {$this->field('name')}, tot snel.</p>", [
            'guest_name' => '<a href="https://kwaadaardig.test">Klik</a> [hier](https://kwaadaardig.test)',
        ]);

        $html = (new BookingMail($booking, MailType::Confirmation))->render();

        // No real link: only as visible text.
        $this->assertDoesNotMatchRegularExpression('/<a\s[^>]*kwaadaardig/', $html);
        $this->assertStringContainsString('Beste <a href="https://kwaadaardig.test">Klik</a> [hier](https://kwaadaardig.test), tot snel.', $this->mailText($booking));
    }

    public function test_a_older_text_without_formatting_stays_as_he_was(): void
    {
        $text = $this->mailText($this->booking('Neem je sleutel mee.'));

        $this->assertStringContainsString('Neem je sleutel mee.', $text);
    }

    public function test_the_editor_offers_the_placeholders_on_in_the_language_of_the_admin(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator, 'locale' => 'en']);
        app()->setLocale('en');

        $editor = collect(Livewire::actingAs($administrator)->test(EditDefaults::class)->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field) => $field->getStatePath() === 'data.mail_texts.confirmation.en');

        $this->assertInstanceOf(RichEditor::class, $editor);
        $tags = $editor->getMergeTags();
        $this->assertSame('Guest name', $tags['name']);
        $this->assertSame('Arrival date', $tags['arrival']);
        $this->assertSame('Company phone number', $tags['phone']);
    }

    public function test_a_text_with_placeholder_is_via_the_form_saved(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $text = "<p>Beste {$this->field('name')}, welkom!</p>";

        Livewire::actingAs($administrator)
            ->test(EditDefaults::class)
            ->fillForm(['mail_texts' => ['confirmation' => ['en' => $text]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = Property::master()->mail_texts['confirmation']['en'];
        $this->assertStringContainsString('data-type="mergeTag"', $saved);
        $this->assertStringContainsString('data-id="name"', $saved);
    }
}
