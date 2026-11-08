<?php

namespace Tests\Feature;

use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_photos_appear_on_the_page_without_double_heading(): void
    {
        $page = Page::create([
            'title' => 'Over ons',
            'published' => true,
            'hero_images' => [[
                'image' => 'media/strand.jpg',
                'image_mobile' => 'media/strand-mobiel.jpg',
                'title' => ['nl' => 'Welkom aan zee', 'en' => 'Welcome at sea'],
                'link' => '/contact',
                'position' => 'bottom-left',
            ]],
        ]);

        $response = $this->withSession(['locale' => 'nl'])->get('/over-ons');
        $response->assertOk()
            ->assertSee('data-hero-slider', false)
            ->assertSee('storage/media/strand.jpg', false)
            ->assertSee('storage/media/strand-mobiel.jpg', false)
            ->assertSee('Welkom aan zee')
            ->assertSee('items-end justify-start', false);

        // the page heading is already on the photo, so not again below it
        $this->assertStringNotContainsString(
            '<h1 class="text-3xl font-bold tracking-tight',
            (string) $response->getContent(),
        );
    }

    public function test_multiple_heroes_are_a_slider_with_dots(): void
    {
        $page = Page::create([
            'title' => 'Home',
            'published' => true,
            'hero_images' => [
                ['image' => 'media/een.jpg', 'title' => [], 'link' => '', 'position' => 'center'],
                ['image' => 'media/twee.jpg', 'title' => [], 'link' => '', 'position' => 'center'],
            ],
        ]);

        $this->get('/home')->assertOk()
            ->assertSee('hero-dot', false)
            ->assertSee('data-slide="1"', false);
    }

    public function test_hero_title_falls_back_on_the_dutch(): void
    {
        $hero = ['title' => ['nl' => 'Welkom']];

        $this->assertSame('Welkom', Page::heroTitle($hero, 'de'));
        $this->assertNull(Page::heroTitle(['title' => []], 'nl'));
    }

    public function test_cleaning_of_the_hero_list(): void
    {
        $clean = Page::cleanHeroes([
            ['image' => 'media/goed.jpg', 'title' => ['nl' => ' Titel ', 'xx' => 'gone', 'en' => ''], 'position' => 'niet-bestaand'],
            ['image' => '', 'title' => []],          // no photo: dropped
            'geen-array',
            ['image' => 'media/twee.jpg', 'link' => '/contact', 'position' => 'top-right'],
        ]);

        $this->assertCount(2, $clean);
        $this->assertSame(['nl' => 'Titel'], $clean[0]['title']);
        $this->assertSame('center', $clean[0]['position']);
        $this->assertSame('top-right', $clean[1]['position']);
        $this->assertSame('/contact', $clean[1]['link']);
    }
}
