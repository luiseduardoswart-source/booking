<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bookings API follows the same rights as the admin: the screen
 * permission for bookings, and a host only their own accommodations.
 */
class BookingApiRightsTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private Property $cabin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beach = $this->accommodation('Beach house', 'beach-house');
        $this->cabin = $this->accommodation('Jungle cabin', 'jungle-cabin');

        // The host only has the cabin.
        $this->host = Host::create(['name' => 'Ana']);
        $this->cabin->hosts()->sync([$this->host->id]);

        $this->booking($this->beach, 'BK-BEACH');
        $this->booking($this->cabin, 'BK-CABIN');
    }

    private function accommodation(string $name, string $slug): Property
    {
        return Property::create([
            'name' => $name, 'slug' => $slug, 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function booking(Property $property, string $number): Booking
    {
        return Booking::create([
            'booking_number' => $number, 'property_id' => $property->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com',
            'arrival_date' => today()->addDays(30)->toDateString(), 'departure_date' => today()->addDays(33)->toDateString(),
            'guests' => 2, 'total_cents' => 30000, 'down_payment_cents' => 9000,
            'price_snapshot' => ['lines' => []], 'locale' => 'en',
        ]);
    }

    private function hostUser(array $bookingRights = [Access::VIEW, Access::CREATE]): User
    {
        return User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $this->host->id, 'permissions' => ['bookings' => $bookingRights],
        ]);
    }

    private function newBooking(Property $property): array
    {
        return [
            'property_id' => $property->id,
            'arrival' => today()->addDays(60)->toDateString(),
            'departure' => today()->addDays(62)->toDateString(),
            'guests' => 2,
            'guest' => ['name' => 'API Gast', 'email' => 'api@example.com'],
        ];
    }

    public function test_the_owner_sees_every_booking(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Administrator]));

        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_host_only_sees_the_bookings_of_their_own_accommodations(): void
    {
        Sanctum::actingAs($this->hostUser());
        $beach = Booking::where('booking_number', 'BK-BEACH')->first();
        $cabin = Booking::where('booking_number', 'BK-CABIN')->first();

        $this->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.booking_number', 'BK-CABIN');

        $this->getJson("/api/v1/bookings/{$beach->id}")->assertNotFound();
        $this->getJson("/api/v1/bookings/{$cabin->id}")->assertOk()->assertJsonPath('data.booking_number', 'BK-CABIN');
    }

    public function test_a_host_only_books_their_own_accommodations(): void
    {
        Sanctum::actingAs($this->hostUser());

        $this->postJson('/api/v1/bookings', $this->newBooking($this->beach))->assertNotFound();
        $this->assertSame(1, Booking::where('property_id', $this->beach->id)->count());

        $this->postJson('/api/v1/bookings', $this->newBooking($this->cabin))->assertCreated();
        $this->assertSame(2, Booking::where('property_id', $this->cabin->id)->count());
    }

    public function test_without_the_permission_for_bookings_nothing_is_possible(): void
    {
        $booking = Booking::first();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['accommodations' => [Access::VIEW]]]));

        $this->getJson('/api/v1/bookings')->assertForbidden();
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertForbidden();
        $this->postJson('/api/v1/bookings', $this->newBooking($this->beach))->assertForbidden();
        $this->assertSame(2, Booking::count());
    }

    public function test_looking_does_not_mean_booking(): void
    {
        Sanctum::actingAs($this->hostUser([Access::VIEW]));

        $this->getJson('/api/v1/bookings')->assertOk();
        $this->postJson('/api/v1/bookings', $this->newBooking($this->cabin))->assertForbidden();
        $this->assertSame(2, Booking::count());
    }
}
