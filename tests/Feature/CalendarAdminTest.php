<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_overview_rendert(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)
            ->get('/admin/calendar-blocks')
            ->assertOk();
    }
}
