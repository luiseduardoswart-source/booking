<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The accommodation page on a phone: the booking card is far down, so a bar
 * at the bottom takes the guest there, and the calendar that opens from the
 * card lies over the map instead of under it.
 */
class PropertyPageMobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function page(): string
    {
        $property = Property::create([
            'name' => 'Casa Cartagena', 'slug' => 'casa-cartagena', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'city' => 'Cartagena', 'country' => 'CO', 'latitude' => 10.4090553, 'longitude' => -75.5514039,
        ]);

        return $this->withSession(['locale' => 'nl'])->get("/{$property->slug}")->assertOk()->getContent();
    }

    public function test_a_bar_at_the_bottom_leads_to_the_booking_card_on_mobile_only(): void
    {
        $html = $this->page();

        $this->assertMatchesRegularExpression('/<div id="book_bar" class="[^"]*\bfixed\b[^"]*\blg:hidden\b/', $html);
        $this->assertStringContainsString('href="#booking_card"', $html);
        $this->assertStringContainsString(__('Reserve'), $html);
        $this->assertStringContainsString(__('per night'), $html);
    }

    public function test_the_booking_card_lies_above_the_map(): void
    {
        $html = $this->page();

        // Sticky makes its own layer; z-10 puts it (and its calendar) above the map's z-0.
        $this->assertMatchesRegularExpression('/<div id="booking_card" class="[^"]*\bsticky\b[^"]*\bz-10\b/', $html);
        $this->assertMatchesRegularExpression('/<div id="map"[^>]*class="z-0\b/s', $html);
    }
}
