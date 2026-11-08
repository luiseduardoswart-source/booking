<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSignature;
use Tests\TestCase;

/**
 * From how many nights the rental terms apply. House rules and
 * cancellation terms always apply.
 */
class RentalTermsFromTest extends TestCase
{
    use MakesSignature, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'rental_terms' => 'Borg wordt ingehouden bij schade.',
            'house_rules' => 'Geen feesten.',
        ], $extra));
    }

    private function book(Property $accommodation, string $arrival, string $departure): Booking
    {
        $this->post("/{$accommodation->slug}/book", [
            'arrival' => $arrival,
            'departure' => $departure,
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            // For a short stay the server ignores this; for a long stay it is required.
            'signature' => $this->signature(),
        ]);

        return Booking::query()->latest('id')->firstOrFail();
    }

    public function test_without_setting_apply_they_always(): void
    {
        $this->assertTrue($this->accommodation()->rentalTermsApply(1));
    }

    public function test_the_default_determines_the_limit(): void
    {
        Property::master()->update(['rental_terms_min_nights' => 7]);
        $accommodation = $this->accommodation();

        $this->assertFalse($accommodation->rentalTermsApply(6));
        $this->assertTrue($accommodation->rentalTermsApply(7));
    }

    public function test_own_limit_goes_for_the_default(): void
    {
        Property::master()->update(['rental_terms_min_nights' => 7]);

        $this->assertTrue($this->accommodation(['rental_terms_min_nights' => 3])->rentalTermsApply(3));
    }

    public function test_own_zero_means_always_also_as_the_default_higher_is(): void
    {
        Property::master()->update(['rental_terms_min_nights' => 7]);

        $this->assertTrue($this->accommodation(['rental_terms_min_nights' => 0])->rentalTermsApply(1));
    }

    public function test_without_rental_terms_apply_they_never(): void
    {
        $this->assertFalse($this->accommodation(['rental_terms' => null])->rentalTermsApply(30));
    }

    public function test_at_a_short_stay_contains_the_proof_only_house_rules_and_cancellation(): void
    {
        $accommodation = $this->accommodation(['rental_terms_min_nights' => 7]);

        $booking = $this->book($accommodation, '2026-12-01', '2026-12-04'); // 3 nights

        $this->assertStringNotContainsString('Borg wordt ingehouden', $booking->terms_snapshot);
        $this->assertStringContainsString('Geen feesten.', $booking->terms_snapshot);
    }

    public function test_at_a_long_stay_contains_the_proof_also_the_rental_terms(): void
    {
        $accommodation = $this->accommodation(['rental_terms_min_nights' => 7]);

        $booking = $this->book($accommodation, '2026-12-01', '2026-12-09'); // 8 nights

        $this->assertStringContainsString('Borg wordt ingehouden', $booking->terms_snapshot);
    }

    public function test_the_booking_form_mentions_the_rental_terms_only_as_they_apply(): void
    {
        $accommodation = $this->accommodation(['rental_terms_min_nights' => 7]);

        $this->get("/{$accommodation->slug}/book?arrival=2026-12-01&departure=2026-12-04&guests=2")
            ->assertOk()
            ->assertSee(__('I agree to the house rules and cancellation policy.'))
            ->assertDontSee('Borg wordt ingehouden');

        $this->get("/{$accommodation->slug}/book?arrival=2026-12-01&departure=2026-12-09&guests=2")
            ->assertOk()
            ->assertSee('Borg wordt ingehouden');
    }
}
