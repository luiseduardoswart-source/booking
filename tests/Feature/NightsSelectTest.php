<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\NightlyPricesCalendar;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Selecting multiple nights in the price calendar: dragging and Shift+click both
 * arrive at the server as a single range, and only there is it decided what is selectable.
 */
class NightsSelectTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function calendar(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(NightlyPricesCalendar::class, ['property' => $this->accommodation]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function bookNights(int $arrival, int $departure): void
    {
        Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $this->accommodation->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => $this->day($arrival), 'departure_date' => $this->day($departure), 'guests' => 2,
            'total_cents' => 32000, 'down_payment_cents' => 9600, 'deposit_cents' => 5000,
            'price_snapshot' => ['lines' => []],
        ]);
    }

    public function test_a_range_selects_all_nights_in_between_also_across_the_month_boundary(): void
    {
        $this->calendar()
            ->call('select', $this->day(10), $this->day(50))
            ->assertCount('selected', 41)
            ->assertSet('selected.0', $this->day(10))
            ->assertSet('selected.40', $this->day(50));
    }

    public function test_dragging_backwards_selects_the_same_nights(): void
    {
        $this->calendar()
            ->call('select', $this->day(14), $this->day(10))
            ->assertSet('selected', array_map($this->day(...), range(10, 14)));
    }

    public function test_a_range_skips_past_and_booked_nights(): void
    {
        $this->bookNights(3, 5);

        $this->calendar()
            ->call('select', $this->day(-2), $this->day(6))
            ->assertSet('selected', array_map($this->day(...), [0, 1, 2, 5, 6]));
    }

    public function test_the_departure_day_of_a_booking_stays_selectable(): void
    {
        $this->bookNights(3, 5);

        $this->calendar()
            ->call('select', $this->day(5), $this->day(5))
            ->assertSet('selected', [$this->day(5)]);
    }

    public function test_a_second_range_is_added_to_the_first_without_duplicate_nights(): void
    {
        $this->calendar()
            ->call('select', $this->day(10), $this->day(12))
            ->call('select', $this->day(12), $this->day(13))
            ->assertSet('selected', array_map($this->day(...), [10, 11, 12, 13]));
    }

    public function test_dragging_from_a_selected_night_removes_only_that_range(): void
    {
        $this->calendar()
            ->call('select', $this->day(10), $this->day(15))
            ->call('select', $this->day(12), $this->day(13), false)
            ->assertSet('selected', array_map($this->day(...), [10, 11, 14, 15]));
    }

    public function test_an_invalid_date_changes_nothing(): void
    {
        $this->calendar()
            ->call('select', $this->day(10), $this->day(10))
            ->call('select', 'no-date', $this->day(12))
            ->assertSet('selected', [$this->day(10)]);
    }

    public function test_a_range_cannot_blow_up_the_selection_without_limit(): void
    {
        $this->calendar()
            ->call('select', $this->day(0), '9999-12-31')
            ->assertCount('selected', 731);
    }

    public function test_a_keyboard_click_toggles_a_night_but_not_if_it_is_booked(): void
    {
        $this->bookNights(3, 5);

        $this->calendar()
            ->call('toggle', $this->day(10))
            ->assertSet('selected', [$this->day(10)])
            ->call('toggle', $this->day(3))
            ->assertSet('selected', [$this->day(10)])
            ->call('toggle', $this->day(10))
            ->assertSet('selected', []);
    }

    public function test_a_price_applies_to_the_whole_dragged_range(): void
    {
        $this->calendar()
            ->call('select', $this->day(10), $this->day(12))
            ->set('priceInput', '150')
            ->call('save');

        $this->assertSame(3, $this->accommodation->nightlyRates()->where('price_cents', 15000)->count());
    }

    public function test_only_selectable_nights_respond_to_the_mouse(): void
    {
        $this->bookNights(40, 42);

        $this->calendar()
            ->call('next')
            ->assertSeeHtml("begin('".$this->day(45)."'")
            ->assertDontSeeHtml("begin('".$this->day(40)."'");
    }
}
