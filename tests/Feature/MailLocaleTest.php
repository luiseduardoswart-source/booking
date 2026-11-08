<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\MailType;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Services\MachineTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class MailLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeBooking(string $locale = 'nl'): Booking
    {
        $property = Property::create([
            'name' => 'Taalhuis',
            'slug' => 'taalhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        app()->setLocale($locale);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'John Smith', 'email' => 'john@example.com'],
        );

        app()->setLocale('nl');

        return $booking;
    }

    public function test_guest_language_is_saved_at_the_booking(): void
    {
        $this->assertSame('en', $this->makeBooking('en')->locale);
        $this->assertSame('nl', $this->makeBooking('nl')->locale);
    }

    public function test_mail_is_rendered_in_the_language_of_the_guest(): void
    {
        $booking = $this->makeBooking('en');

        $mail = new BookingMail($booking, MailType::Confirmation);

        $this->assertStringContainsString('Thank you for your booking, John Smith!', $mail->render());
        $this->assertStringContainsString('Price breakdown', $mail->render());
    }

    public function test_mail_in_the_german_for_german_guest(): void
    {
        $booking = $this->makeBooking('de');

        $mail = new BookingMail($booking, MailType::Confirmation);

        $this->assertStringContainsString('Danke für deine Buchung, John Smith!', $mail->render());
    }

    public function test_own_mail_text_follows_the_guest_language(): void
    {
        Property::master()->update(['mail_texts' => ['confirmation' => [
            'nl' => 'Vergeet je zwemkleding niet!',
            'en' => 'Do not forget your swimwear!',
        ]]]);

        $nl = new BookingMail($this->makeBooking('nl'), MailType::Confirmation);
        $en = new BookingMail($this->makeBooking('en'), MailType::Confirmation);

        $this->assertStringContainsString('Vergeet je zwemkleding niet!', $nl->render());
        $this->assertStringContainsString('Do not forget your swimwear!', $en->render());
    }

    public function test_translate_button_fills_empty_languages_from_the_english(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });

        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'company_slogan_en' => 'Sleep by the sea',
            ])
            ->callAction('translate')
            ->assertFormSet([
                'company_slogan_nl' => '[nl] Sleep by the sea',
                'company_slogan_de' => '[de] Sleep by the sea',
            ]);
    }

    public function test_translate_button_without_api_key_gives_proper_notification(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm(['company_slogan_nl' => 'Slaap aan zee'])
            ->callAction('translate')
            ->assertNotified();
    }
}
