<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AiImage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiImageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function withKey(): void
    {
        Setting::set('ai', 'openai_api_key', 'sk-test');
    }

    public function test_generate_requires_admin_and_a_key(): void
    {
        Storage::fake('public');

        // guest first (actingAs still applies)
        $this->postJson('/admin-media/ai/generate', ['prompt' => 'x'])->assertStatus(403);

        // admin without key: feature does not exist (404), like devsite
        $this->actingAs($this->admin())
            ->postJson('/admin-media/ai/generate', ['prompt' => 'x'])
            ->assertStatus(404);
    }

    public function test_generate_set_images_in_the_queue(): void
    {
        Storage::fake('public');
        $this->withKey();
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('nep-png')]]]),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/admin-media/ai/generate', ['prompt' => 'houtkachel in woonkamer', 'format' => 'landscape', 'count' => 2])
            ->assertOk()
            ->assertJsonCount(2, 'images')
            ->assertJsonPath('usage', 2);

        $this->assertSame(2, AiImage::where('status', 'pending')->count());
        $first = AiImage::first();
        $this->assertStringStartsWith('ai-pending/', $first->path);
        Storage::disk('public')->assertExists($first->path);
        $this->assertSame('1792x1024', $first->size);
    }

    public function test_monthly_limit_is_enforced(): void
    {
        Storage::fake('public');
        $this->withKey();
        Setting::set('ai', 'image_monthly_limit', '2');

        AiImage::create(['prompt' => 'a', 'status' => 'rejected', 'month' => now()->format('Y-m')]);
        AiImage::create(['prompt' => 'b', 'status' => 'approved', 'month' => now()->format('Y-m')]);

        $this->actingAs($this->admin())
            ->postJson('/admin-media/ai/generate', ['prompt' => 'nog een'])
            ->assertStatus(429);
    }

    public function test_approve_moved_to_the_media_dir_with_proper_name(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('ai-pending/ai_x.png', 'image');

        $img = AiImage::create([
            'prompt' => 'Een sfeervolle houtkachel in een moderne woonkamer',
            'path' => 'ai-pending/ai_x.png',
            'status' => 'pending',
            'month' => now()->format('Y-m'),
        ]);

        $this->actingAs($this->admin())
            ->postJson("/admin-media/ai/{$img->id}/approve", ['folder' => 'atmosphere'])
            ->assertOk()
            ->assertJsonPath('path', 'media/atmosphere/een-sfeervolle-houtkachel-in-een.png');

        $disk->assertExists('media/atmosphere/een-sfeervolle-houtkachel-in-een.png');
        $disk->assertMissing('ai-pending/ai_x.png');
        $this->assertSame('approved', $img->fresh()->status);

        // target folder with ../ is sanitised, not followed
        $disk->put('ai-pending/ai_y.png', 'image');
        $img2 = AiImage::create(['prompt' => 'x', 'path' => 'ai-pending/ai_y.png', 'status' => 'pending', 'month' => now()->format('Y-m')]);
        $this->postJson("/admin-media/ai/{$img2->id}/approve", ['folder' => '../outside'])
            ->assertOk()
            ->assertJsonPath('path', 'media/outside/x.png');
    }

    public function test_reject_deletes_the_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('ai-pending/ai_z.png', 'image');

        $img = AiImage::create(['prompt' => 'gone', 'path' => 'ai-pending/ai_z.png', 'status' => 'pending', 'month' => now()->format('Y-m')]);

        $this->actingAs($this->admin())
            ->postJson("/admin-media/ai/{$img->id}/reject")
            ->assertOk();

        Storage::disk('public')->assertMissing('ai-pending/ai_z.png');
        $this->assertSame('rejected', $img->fresh()->status);
    }

    public function test_panel_is_on_the_media_page(): void
    {
        $this->actingAs($this->admin())
            ->get(\App\Filament\Pages\MediaManager::getUrl())
            ->assertOk()
            ->assertSee('ai-images-panel', false)
            ->assertSee('ai-image-modal', false);
    }
}
