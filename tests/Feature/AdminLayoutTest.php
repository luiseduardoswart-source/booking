<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Support\SidebarWidth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin uses the full width next to the sidebar, and the sidebar has
 * a draggable edge.
 */
class AdminLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_used_the_full_width_and_the_sidebar_is_draggable(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get('/admin/bookings')
            ->assertOk()
            ->assertSee('fi-width-full', false)
            ->assertDontSee('fi-width-7xl', false)
            ->assertSee('data-sidebar-drag-edge', false)
            ->assertSee('data-min="'.SidebarWidth::MIN.'"', false)
            ->assertSee('js/sidebar-width.js', false)
            // The stored width is already set in the head, before the first render.
            ->assertSee("localStorage.getItem('".SidebarWidth::KEY."')", false);
    }
}
