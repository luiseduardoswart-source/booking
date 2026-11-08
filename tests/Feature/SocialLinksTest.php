<?php

namespace Tests\Feature;

use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialLinksTest extends TestCase
{
    use RefreshDatabase;

    private function property(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Socialhuis', 'slug' => 'socialhuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ], $overrides));
    }

    public function test_accommodation_inherits_the_general_links(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/company', 'instagram' => 'https://instagram.com/company']]);

        $property = $this->property();

        $this->assertSame([
            'facebook' => 'https://facebook.com/company',
            'instagram' => 'https://instagram.com/company',
        ], $property->socialLinks());
    }

    public function test_own_link_overrides_the_general(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/company', 'instagram' => 'https://instagram.com/company']]);

        $property = $this->property([
            'social_links' => ['instagram' => 'https://instagram.com/this-house'],
        ]);

        $links = $property->socialLinks();

        $this->assertSame('https://instagram.com/this-house', $links['instagram']);
        $this->assertSame('https://facebook.com/company', $links['facebook']);
    }

    public function test_unsafe_and_unknown_links_are_rejected(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'javascript:alert(1)']]);

        $property = $this->property([
            'social_links' => ['instagram' => 'data:text/html,x', 'unknown' => 'https://example.nl'],
        ]);

        $this->assertSame([], $property->socialLinks());
    }

    public function test_links_appear_on_the_detail_page_and_in_the_footer(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/company']]);
        $property = $this->property(['social_links' => ['instagram' => 'https://instagram.com/this-house']]);

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee(__('Follow us'))
            ->assertSee('https://instagram.com/this-house', false)
            ->assertSee('https://facebook.com/company', false);
    }

    public function test_no_links_no_heading(): void
    {
        $property = $this->property();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee(__('Follow us'));
    }
}
