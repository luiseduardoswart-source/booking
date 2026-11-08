<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bookings table follows the same order as Payments: the newest booking
 * is at the top, regardless of when the guest arrives.
 */
class BookingsOrderTest extends TestCase
{
    use RefreshDatabase;

    private function booking(Property $accommodation, string $arrival, string $created): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => $arrival, 'departure_date' => date('Y-m-d', strtotime($arrival.' +2 days')), 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);

        $booking->forceFill(['created_at' => $created])->save();

        return $booking;
    }

    public function test_the_newest_booking_is_at_the_top_even_with_an_earlier_arrival(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $old = $this->booking($accommodation, '2026-12-20', '2026-10-01 10:00:00');
        $new = $this->booking($accommodation, '2026-12-05', '2026-10-03 10:00:00');
        $middle = $this->booking($accommodation, '2026-12-10', '2026-10-02 10:00:00');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(ListBookings::class)
            ->assertCanSeeTableRecords([$new, $middle, $old], inOrder: true);
    }
}
