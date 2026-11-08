<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_guest_has_no_access_until_the_admin_panel(): void
    {
        $guest = User::factory()->create(['role' => UserRole::Guest]);

        $this->actingAs($guest)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_accommodation_overview_rendert(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/properties')
            ->assertOk();
    }

    public function test_accommodation_creation_form_rendert(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/properties/create')
            ->assertOk();
    }

    public function test_amenities_overview_rendert(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/admin/amenities')
            ->assertOk();
    }

    public function test_edit_screen_rendert_for_existing_accommodation(): void
    {
        $property = Property::create([
            'name' => 'Testhuis aan Zee',
            'slug' => 'testhuis-on-sea',
            'type' => 'holiday_home',
            'max_guests' => 4,
        ]);

        $this->actingAs($this->adminUser())
            ->get("/admin/properties/{$property->id}/edit")
            ->assertOk();
    }
}
