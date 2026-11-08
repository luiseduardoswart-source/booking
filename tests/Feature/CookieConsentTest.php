<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use App\Support\CookieConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        return Property::create([
            'name' => 'Cookiehuis', 'slug' => 'cookiehuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
    }

    public function test_banner_only_active_with_something_to_consent_for_to_ask(): void
    {
        $this->assertFalse(CookieConsent::active());

        // On, but nothing to consent to → not active yet.
        Setting::set('cookie', 'on', '1');
        $this->assertFalse(CookieConsent::active());

        // Statistics script added → active.
        Setting::set('cookie', 'code_statistics', '<script>/* GA */</script>');
        $this->assertTrue(CookieConsent::active());
    }

    public function test_scripts_are_apply_loaded_after_consent(): void
    {
        Setting::set('cookie', 'on', '1');
        Setting::set('cookie', 'code_statistics', '<script>window.__ga=1</script>');
        $property = $this->property();

        // Without a choice: banner visible, script NOT loaded.
        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('cc-banner', false)
            ->assertDontSee('window.__ga=1', false);

        // With consent for statistics: script loaded.
        $this->withUnencryptedCookie('cookie_consent', json_encode(['v' => 1, 'necessary' => true, 'statistics' => true]))
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('window.__ga=1', false);

        // Declined: not loaded again.
        $this->withUnencryptedCookie('cookie_consent', json_encode(['v' => 1, 'necessary' => true, 'statistics' => false]))
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('window.__ga=1', false);
    }

    public function test_disabled_notification_shows_nothing_and_loads_scripts_always(): void
    {
        Setting::set('cookie', 'code_statistics', '<script>window.__ga=2</script>');
        $property = $this->property();

        // Notification is off (cookie/on = 0): no banner, script is loaded
        // (then it is the admin's responsibility).
        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('cc-banner', false)
            ->assertSee('window.__ga=2', false);
    }

    public function test_own_title_and_privacy_link(): void
    {
        Setting::set('cookie', 'on', '1');
        Setting::set('cookie', 'code_statistics', '<script>x</script>');
        Setting::set('cookie', 'title_nl', 'Wij en koekjes');
        Setting::set('cookie', 'privacy_url', 'https://example.nl/privacy');
        $property = $this->property();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Wij en koekjes')
            ->assertSee('https://example.nl/privacy', false);
    }

    public function test_marketing_code_only_with_marketing_consent(): void
    {
        Setting::set('cookie', 'on', '1');
        Setting::set('cookie', 'marketing_on', '1');
        Setting::set('cookie', 'code_marketing', '<script>window.__pixel=1</script>');
        $property = $this->property();

        $this->assertContains('marketing', CookieConsent::categories());

        $this->withUnencryptedCookie('cookie_consent', json_encode(['v' => 1, 'marketing' => true]))
            ->get("/{$property->slug}")
            ->assertSee('window.__pixel=1', false);

        $this->withUnencryptedCookie('cookie_consent', json_encode(['v' => 1, 'marketing' => false]))
            ->get("/{$property->slug}")
            ->assertDontSee('window.__pixel=1', false);
    }
}
