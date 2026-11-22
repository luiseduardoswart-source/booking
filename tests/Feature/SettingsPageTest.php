<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_settings_save(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm([
                'company_name' => 'Duinhuisjes Verhuur',
                'company_email' => 'info@duinhuisjes.nl',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Duinhuisjes Verhuur', Setting::get('company', 'name'));
        $this->assertSame('info@duinhuisjes.nl', Setting::get('company', 'email'));
    }

    public function test_staff_has_no_access_until_settings(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $this->actingAs($staff)
            ->get(Settings::getUrl())
            ->assertForbidden();
    }

    public function test_multilingual_slogan_follows_the_language_of_the_visitor(): void
    {
        Setting::set('company', 'slogan_nl', 'Slaap aan zee');
        Setting::set('company', 'slogan_en', 'Sleep by the sea');

        // Two properties so that the homepage (with tagline) is shown.
        foreach (['house-a', 'house-b'] as $slug) {
            \App\Models\Property::create([
                'name' => ucfirst($slug), 'slug' => $slug, 'type' => 'holiday_home',
                'max_guests' => 2, 'active' => true,
            ]);
        }

        $this->get('/')->assertSee('Slaap aan zee');
        $this->get('/en')->assertSee('Sleep by the sea');
    }

    public function test_own_mail_text_comes_in_the_confirmation_mail(): void
    {
        \App\Models\Property::master()->update(['mail_texts' => ['confirmation' => ['nl' => 'Vergeet je zwemkleding niet mee te nemen!']]]);

        $property = \App\Models\Property::create([
            'name' => 'Mailhuis', 'slug' => 'mailhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $booking = app(\App\Engines\BookingEngine::class)->create(
            $property,
            \Carbon\CarbonImmutable::parse('2026-03-02'),
            \Carbon\CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan', 'email' => 'jan@example.com'],
        );

        $mail = new \App\Mail\BookingMail($booking, \App\Enums\MailType::Confirmation);

        $this->assertStringContainsString('Vergeet je zwemkleding niet mee te nemen!', $mail->render());
    }

    public function test_map_appears_on_detail_page_with_coordinates(): void
    {
        $property = \App\Models\Property::create([
            'name' => 'Kaarthuis',
            'slug' => 'kaarthuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'latitude' => 52.2411,
            'longitude' => 4.43,
        ]);

        // Approximate location (the default): the circle's centre, not the house itself.
        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('id="map"', false)
            ->assertSee('data-lat="'.$property->publicCoordinates()['lat'].'"', false)
            ->assertDontSee('data-lat="52.2411000"', false);
    }

    public function test_no_map_without_coordinates(): void
    {
        $property = \App\Models\Property::create([
            'name' => 'Zonder Kaart',
            'slug' => 'without-map',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
        ]);

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('id="map"', false);
    }
}
