<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Engines\BookingEngine;
use App\Engines\PricingEngine;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_bookings_overview_rendert(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/bookings')
            ->assertOk();
    }

    public function test_booking_creation_form_rendert(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/bookings/create')
            ->assertOk();
    }

    public function test_edit_screen_rendert_for_existing_booking(): void
    {
        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $this->actingAs($this->admin())
            ->get("/admin/bookings/{$booking->id}/edit")
            ->assertOk();
    }
}
