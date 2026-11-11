<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Support\VideoBlock;
use App\Models\Page;
use App\Models\Property;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A video from the media library in a site text: a video block in the
 * editor, a video player on the site instead of a bare link.
 */
class VideoBlockTest extends TestCase
{
    use RefreshDatabase;

    private const SRC = 'http://airbnb.test/storage/media/trailer.mp4';

    /** How the editor stores a video block. */
    private static function block(string $src = self::SRC, string $id = 'video'): string
    {
        return '<div data-type="customBlock" data-config="'.e(json_encode(['src' => $src])).'" data-id="'.$id.'"></div>';
    }

    public function test_the_block_becomes_a_video_player_and_the_rest_stays_exactly_the_same(): void
    {
        $around = '<p class="lead">Welcome</p><p><img src="http://airbnb.test/storage/media/content/a.jpg" alt="A"></p>';

        $html = RichText::render($around.self::block().'<p>After</p>');

        $this->assertSame(
            $around.'<video src="'.self::SRC.'#t=0.1" controls preload="metadata" playsinline style="width:100%;height:auto;border-radius:0.75rem"></video><p>After</p>',
            $html,
        );
        // Without a block nothing changes, not even a little.
        $this->assertSame($around, RichText::render($around));
    }

    public function test_a_dangerous_address_or_unknown_block_disappears(): void
    {
        $this->assertSame('<p>x</p>', RichText::render('<p>x</p>'.self::block('javascript:alert(1)')));
        $this->assertSame('<p>x</p>', RichText::render('<p>x</p>'.self::block('/a.mp4" onerror="alert(1)')));
        $this->assertSame('<p>x</p>', RichText::render('<p>x</p>'.self::block(id: 'unknown')));
    }

    public function test_a_text_with_only_a_video_counts_as_filled(): void
    {
        $this->assertTrue(RichText::filled('<p></p>'.self::block()));
    }

    public function test_the_accommodation_page_and_a_page_show_the_video(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'description' => '<p>Look:</p>'.self::block(),
        ]);
        Page::create(['title' => 'Tour', 'slug' => 'tour', 'content' => '<p>Look:</p>'.self::block(), 'published' => true]);

        foreach ([route('properties.show', $accommodation->slug), '/tour'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('<video src="'.self::SRC.'#t=0.1" controls', false)
                ->assertDontSee('data-type="customBlock"', false);
        }
    }

    public function test_the_media_library_button_inserts_a_video_block_with_the_same_preview_as_the_server(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertFormFieldExists(
                'translations_name_short_description_description.name_short_description_description_en.description',
                'form',
                fn ($editor): bool => $editor->getCustomBlock('video') === VideoBlock::class,
            )
            ->html();

        // The script of the button (Filament puts it unescaped in the attribute): no double quote, or the attribute breaks off.
        $this->assertSame(1, preg_match('#data-rich-editor-tool="mediaLibrary"[^>]*x-on:click="([^"]*)"#', $html, $script)
            ?: preg_match('#x-on:click="(window\.pickMedia[^"]*)"[^>]*data-rich-editor-tool="mediaLibrary"#', $html, $script));
        $this->assertStringContainsString("type: 'customBlock'", $script[1]);
        $this->assertStringContainsString('$getEditor().chain().focus().insertContent(content).run();', $script[1]);
        $this->assertStringContainsString((string) Js::from(VideoBlock::toPreviewHtml(['src' => '/__SRC__'])), $script[1]);
        $this->assertStringContainsString((string) Js::from(['mp4', 'webm']), $script[1]);
    }
}
