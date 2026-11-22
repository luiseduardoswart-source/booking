<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_page_is_reachable_on_her_slug(): void
    {
        Page::create([
            'title' => 'Over ons',
            'content' => '<p>Wij verhuren al jaren met plezier.</p>',
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get('/over-ons')
            ->assertOk()
            ->assertSee('Over ons')
            ->assertSee('Wij verhuren al jaren met plezier.');
    }

    public function test_not_published_page_gives_404(): void
    {
        Page::create(['title' => 'Concept', 'published' => false]);

        $this->get('/concept')->assertNotFound();
    }

    public function test_accommodation_wins_of_page_with_same_slug(): void
    {
        Property::create([
            'name' => 'Dubbel', 'slug' => 'dubbel', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
        Page::create(['title' => 'Dubbel', 'slug' => 'dubbel', 'content' => '<p>PAGINA-INHOUD</p>']);

        $this->get('/dubbel')
            ->assertOk()
            ->assertDontSee('PAGINA-INHOUD');
    }

    public function test_translated_title_and_content(): void
    {
        Page::create([
            'title' => 'Over ons',
            'title_translations' => ['en' => 'About us'],
            'content' => '<p>Nederlandse tekst</p>',
            'content_translations' => ['en' => '<p>English text</p>'],
        ]);

        $this->get('/en/about-us')
            ->assertOk()
            ->assertSee('About us')
            ->assertSee('English text')
            ->assertDontSee('Nederlandse tekst');

        // A language without a translation falls back to Dutch.
        $this->get('/fr/over-ons')
            ->assertOk()
            ->assertSee('Nederlandse tekst');
    }

    public function test_menu_pages_appear_in_header_and_footer(): void
    {
        Page::create(['title' => 'Over ons', 'in_menu' => true, 'sort' => 1]);
        Page::create(['title' => 'Voorwaarden', 'in_menu' => false, 'in_footer' => true]);
        Page::create(['title' => 'Verborgen', 'in_menu' => true, 'published' => false]);

        $content = $this->withSession(['locale' => 'nl'])->get('/over-ons')->getContent();

        $this->assertStringContainsString('/over-ons', $content);
        $this->assertStringContainsString('/voorwaarden', $content);
        $this->assertStringNotContainsString('/verborgen', $content);
    }

    public function test_slug_is_automatic_made(): void
    {
        $page = Page::create(['title' => 'Veelgestelde Vragen']);

        $this->assertSame('veelgestelde-vragen', $page->slug);
    }
}
