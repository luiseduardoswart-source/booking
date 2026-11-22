<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\StyleEditor;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use App\Support\ContentLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentLinkTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        return Property::create([
            'name' => 'Koppelhuis', 'slug' => 'koppelhuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
    }

    public function test_title_link_replaces_the_text_serverside_and_multilingual(): void
    {
        $property = $this->property();
        $page = Page::create([
            'title' => 'Welkom bij ons',
            'title_translations' => ['en' => 'Welcome to us'],
            'published' => true,
        ]);

        ContentLink::link('h1', $page->id, 'title');

        // Dutch (default)
        $this->followingRedirects()->withSession(['locale' => 'nl'])->get("/{$property->slug}")
            ->assertOk()->assertSee('Welkom bij ons');

        // English: the translation of the page is filled in
        $this->followingRedirects()->withSession(['locale' => 'en'])->get("/{$property->slug}")
            ->assertOk()->assertSee('Welcome to us');
    }

    public function test_content_link_fills_html_but_strips_scripts(): void
    {
        $property = $this->property();
        $page = Page::create([
            'title' => 'Blok',
            'content' => '<p>Mooie <strong>opmaak</strong></p><script>alert(1)</script><span onclick="boem()">klik</span>',
            'published' => true,
        ]);

        ContentLink::link('footer', $page->id, 'content');

        $response = $this->get("/{$property->slug}");
        $response->assertOk()
            ->assertSee('<strong>opmaak</strong>', false)
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('boom', false);
    }

    public function test_unusable_selector_breaks_the_page_not(): void
    {
        $property = $this->property();
        $page = Page::create(['title' => 'Oke', 'published' => true]);

        // set directly in the setting: clean() would reject this too,
        // but even a valid-but-unknown selector must never break anything
        ContentLink::link('div:not(.bestaat-niet-xyz-123)', $page->id, 'title');

        $this->get("/{$property->slug}")->assertOk();
    }

    public function test_cleaning_rejects_unsafe_selectors_and_unknown_fields(): void
    {
        $clean = ContentLink::clean([
            ['selector' => '</style><script>', 'page_id' => 1, 'field' => 'title'],
            ['selector' => '@import "x"', 'page_id' => 1, 'field' => 'title'],
            ['selector' => 'h1', 'page_id' => 0, 'field' => 'title'],
            ['selector' => 'h2', 'page_id' => 3, 'field' => 'password'],
        ]);

        $this->assertCount(1, $clean);
        $this->assertSame(['selector' => 'h2', 'page_id' => 3, 'field' => 'title'], $clean[0]);
    }

    public function test_link_and_disconnect_via_the_editor_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $page = Page::create(['title' => 'Over ons', 'published' => true]);

        Livewire::actingAs($admin)
            ->test(StyleEditor::class)
            ->call('link', '.hero-title', $page->id, 'title');

        $this->assertSame(
            ['selector' => '.hero-title', 'page_id' => $page->id, 'field' => 'title'],
            ContentLink::beforeSelector('.hero-title'),
        );

        Livewire::actingAs($admin)
            ->test(StyleEditor::class)
            ->call('disconnect', '.hero-title');

        $this->assertNull(ContentLink::beforeSelector('.hero-title'));
        // setting is cleaned up, so the site no longer parses anything
        $this->assertSame([], ContentLink::all());
    }

    public function test_image_link_fills_img_src_and_background_with_aspect_ratio(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        // real 1x1 png so getimagesize can read the ratio
        \Illuminate\Support\Facades\Storage::disk('public')->put('media/foto.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        ContentLink::linkImage('img.hoofdfoto', 'media/foto.png');
        ContentLink::linkImage('div.hero', 'media/foto.png');

        $html = ContentLink::apply(
            '<html><head></head><body><img class="hoofdfoto" src="oud.jpg" srcset="oud-2x.jpg 2x"><div class="hero">tekst</div></body></html>'
        );

        // an <img> gets a new src, without the old srcset
        $this->assertStringContainsString('src="'.asset('storage/media/foto.png').'"', $html);
        $this->assertStringNotContainsString('srcset', $html);

        // every other element gets the photo as a cover background, anchored to the
        // top, and grows with the ratio of the photo (51be38c)
        $this->assertStringContainsString('background-size:cover', $html);
        $this->assertStringContainsString('background-position:center top', $html);
        $this->assertStringContainsString('aspect-ratio:1/1', $html);
    }

    public function test_hero_link_shows_the_hero_photos_of_a_page_as_carousel(): void
    {
        $page = Page::create([
            'title' => 'Home',
            'published' => true,
            'hero_images' => [
                ['image' => 'media/een.jpg', 'title' => [], 'link' => '', 'position' => 'center'],
                ['image' => 'media/twee.jpg', 'title' => [], 'link' => '', 'position' => 'center'],
            ],
        ]);

        ContentLink::link('div.banner', $page->id, 'hero');

        $html = ContentLink::apply('<html><head></head><body><div class="banner"></div></body></html>');

        // first photo as background, switching via css keyframes in the head
        $this->assertStringContainsString('media/een.jpg', $html);
        $this->assertStringContainsString('@keyframes sw-hero-0', $html);
        $this->assertStringContainsString('media/twee.jpg', $html);
        $this->assertStringContainsString('animation:sw-hero-0 12s infinite', $html);
    }

    public function test_image_link_via_the_editor_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(StyleEditor::class)
            ->call('linkImage', '.hero', 'media/strand.jpg');

        $this->assertSame(
            ['selector' => '.hero', 'field' => 'image', 'value' => 'media/strand.jpg'],
            ContentLink::beforeSelector('.hero'),
        );

        // path traversal in the media path is rejected: the existing link
        // stays (the guard in linkImage and clean() reject '..')
        try {
            Livewire::actingAs($admin)
                ->test(StyleEditor::class)
                ->call('linkImage', '.hero', '../.env');
        } catch (\Throwable) {
            // a 422 abort may also come out as an exception
        }
        $this->assertSame('media/strand.jpg', ContentLink::beforeSelector('.hero')['value']);
    }

    public function test_photo_position_is_a_allowed_style_property(): void
    {
        $clean = \App\Support\VisualStyle::clean([
            ['selector' => '.hero', 'declarations' => ['background-position' => 'center bottom', 'background-size' => 'contain']],
        ]);

        $this->assertSame(
            ['background-position' => 'center bottom', 'background-size' => 'contain'],
            $clean[0]['declarations'],
        );
    }

    public function test_without_links_stays_the_response_untouched(): void
    {
        $property = $this->property();

        $first = $this->get("/{$property->slug}");
        $first->assertOk();

        // nothing linked: the HTML does not go through the parser (exactly equal)
        $this->assertStringContainsString('</body>', (string) $first->getContent());
    }
}
