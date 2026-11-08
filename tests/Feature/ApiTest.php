<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The fixed dates in these tests (early March 2026) are in the future
        // as seen from this date; arriving in the past is not possible.
        $this->travelTo('2026-02-01 10:00:00');
        Mail::fake();
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'name' => 'API Huis',
            'slug' => 'api-house',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ], $overrides));
    }

    public function test_properties_index_shows_only_active(): void
    {
        $this->makeProperty();
        $this->makeProperty(['name' => 'Inactief', 'slug' => 'inactive', 'active' => false]);

        $this->getJson('/api/v1/properties')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'API Huis');
    }

    public function test_property_detail_gives_fields(): void
    {
        $property = $this->makeProperty();

        $this->getJson("/api/v1/properties/{$property->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'api-house')
            ->assertJsonPath('data.base_price_cents', 10000);
    }

    public function test_quote_endpoint_under_v1(): void
    {
        $property = $this->makeProperty();

        $this->getJson("/api/v1/properties/{$property->id}/quote?arrival=2026-03-02&departure=2026-03-05&guests=2")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('quote.total_cents', 30000);
    }

    public function test_booking_create_requires_authentication(): void
    {
        $property = $this->makeProperty();

        $this->postJson('/api/v1/bookings', ['property_id' => $property->id])
            ->assertUnauthorized();
    }

    public function test_booking_create_via_api(): void
    {
        $property = $this->makeProperty();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $response = $this->postJson('/api/v1/bookings', [
            'property_id' => $property->id,
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'guest' => ['name' => 'API Gast', 'email' => 'api@example.com'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.guest_name', 'API Gast')
            ->assertJsonPath('data.total_cents', 30000);

        $this->assertSame(1, Booking::count());
    }

    public function test_double_booking_via_api_gives_422_with_reasons(): void
    {
        $property = $this->makeProperty();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $payload = [
            'property_id' => $property->id,
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'guest' => ['name' => 'API Gast', 'email' => 'api@example.com'],
        ];

        $this->postJson('/api/v1/bookings', $payload)->assertCreated();
        $this->postJson('/api/v1/bookings', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['reasons']);
    }

    public function test_bookings_list_via_api(): void
    {
        $property = $this->makeProperty();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $this->postJson('/api/v1/bookings', [
            'property_id' => $property->id,
            'arrival' => '2026-03-02',
            'departure' => '2026-03-05',
            'guests' => 2,
            'guest' => ['name' => 'API Gast', 'email' => 'api@example.com'],
        ]);

        $this->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_dashboard_renders_with_widgets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator, 'locale' => 'nl']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\StatsOverview::class)
            ->assertSee('Omzet deze maand');

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\TodayAgenda::class)
            ->assertSee('Agenda');
    }
}
