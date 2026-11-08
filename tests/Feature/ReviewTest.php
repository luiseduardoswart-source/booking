<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\BookingStatus;
use App\Enums\ReviewStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function makeDepartedBooking(): Booking
    {
        $property = Property::create([
            'name' => 'Reviewhuis',
            'slug' => 'reviewhuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse(today()->subDays(5)->toDateString()),
            CarbonImmutable::parse(today()->subDays(2)->toDateString()),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $booking->update(['status' => BookingStatus::Completed]);

        return $booking->refresh();
    }

    public function test_review_form_via_signed_link(): void
    {
        $booking = $this->makeDepartedBooking();

        $this->withSession(['locale' => 'nl'])
            ->get(URL::signedRoute('reviews.create', $booking))
            ->assertOk()
            ->assertSee('Hoe was je verblijf?');
    }

    public function test_review_form_without_signature_is_rejected(): void
    {
        $booking = $this->makeDepartedBooking();

        $this->get("/booking/{$booking->id}/review")->assertForbidden();
    }

    public function test_review_submit_comes_in_pending(): void
    {
        \App\Models\Setting::set('reviews', 'auto_approve', '0');

        $booking = $this->makeDepartedBooking();

        $this->post(URL::signedRoute('reviews.store', $booking), [
            'rating' => 5,
            'comment' => 'Fantastisch verblijf, alles was perfect geregeld!',
        ])->assertRedirect();

        $review = Review::first();
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertSame(5, $review->rating);
        $this->assertSame('Jan Jansen', $review->guest_name);

        // Not yet visible on the site as long as it is not approved.
        $this->withSession(['locale' => 'nl'])
            ->get('/reviewhuis')
            ->assertDontSee('Fantastisch verblijf');
    }

    public function test_four_stars_is_automatic_approved(): void
    {
        $booking = $this->makeDepartedBooking();

        $this->post(URL::signedRoute('reviews.store', $booking), [
            'rating' => 4,
            'comment' => 'Prima verblijf, zou terugkomen.',
        ])->assertRedirect();

        $this->assertSame(ReviewStatus::Approved, $booking->review()->first()->status);

        // And therefore immediately visible on the site.
        $this->withSession(['locale' => 'nl'])
            ->get('/reviewhuis')
            ->assertSee('Prima verblijf, zou terugkomen.');
    }

    public function test_five_stars_is_automatic_approved(): void
    {
        $booking = $this->makeDepartedBooking();

        $this->post(URL::signedRoute('reviews.store', $booking), [
            'rating' => 5,
            'comment' => 'Werkelijk alles klopte.',
        ])->assertRedirect();

        $this->assertSame(ReviewStatus::Approved, $booking->review()->first()->status);
    }

    public function test_lower_review_stays_always_wait_on_approval(): void
    {
        $booking = $this->makeDepartedBooking();

        $this->post(URL::signedRoute('reviews.store', $booking), [
            'rating' => 3,
            'comment' => 'Het kon beter.',
        ])->assertRedirect();

        $this->assertSame(ReviewStatus::Pending, $booking->review()->first()->status);
    }

    public function test_threshold_is_configurable(): void
    {
        \App\Models\Setting::set('reviews', 'auto_approve_from', '5');

        $booking = $this->makeDepartedBooking();

        $this->post(URL::signedRoute('reviews.store', $booking), [
            'rating' => 4,
            'comment' => 'Vier sterren, maar de drempel staat op vijf.',
        ])->assertRedirect();

        $this->assertSame(ReviewStatus::Pending, $booking->review()->first()->status);
    }

    public function test_approved_review_appears_with_average_on_the_site(): void
    {
        $booking = $this->makeDepartedBooking();
        $booking->review()->create([
            'property_id' => $booking->property_id,
            'rating' => 4,
            'comment' => 'Heerlijk gelegen, komen zeker terug.',
            'guest_name' => 'Jan Jansen',
            'status' => ReviewStatus::Approved,
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get('/reviewhuis')
            ->assertOk()
            ->assertSee('Heerlijk gelegen, komen zeker terug.')
            ->assertSee('4,0')
            ->assertSee('1 beoordeling')
            ->assertSee('geverifieerd verblijf')
            ->assertSee('AggregateRating', false); // schema.org for SEO stars
    }

    public function test_second_review_for_same_booking_is_blocked(): void
    {
        $booking = $this->makeDepartedBooking();
        $booking->review()->create([
            'property_id' => $booking->property_id,
            'rating' => 5,
            'guest_name' => 'Jan Jansen',
            'status' => ReviewStatus::Approved,
        ]);

        $this->post(URL::signedRoute('reviews.store', $booking), ['rating' => 1])
            ->assertForbidden();

        $this->assertSame(1, Review::count());
    }

    public function test_review_for_future_stay_can_not(): void
    {
        $property = Property::create([
            'name' => 'Toekomsthuis',
            'slug' => 'toekomsthuis',
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        $booking = app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse(today()->addDays(10)->toDateString()),
            CarbonImmutable::parse(today()->addDays(13)->toDateString()),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );

        $this->withSession(['locale' => 'nl'])
            ->get(URL::signedRoute('reviews.create', $booking))
            ->assertOk()
            ->assertSee('na afloop van je verblijf');

        $this->post(URL::signedRoute('reviews.store', $booking), ['rating' => 5])
            ->assertForbidden();
    }

    public function test_host_reply_appears_under_the_review(): void
    {
        $booking = $this->makeDepartedBooking();
        $booking->review()->create([
            'property_id' => $booking->property_id,
            'rating' => 4,
            'comment' => 'Mooi huisje!',
            'guest_name' => 'Jan Jansen',
            'status' => ReviewStatus::Approved,
            'host_reply' => 'Dankjewel Jan, jullie waren fantastische gasten!',
            'host_replied_at' => now(),
        ]);

        $this->withSession(['locale' => 'nl'])
            ->get('/reviewhuis')
            ->assertOk()
            ->assertSee('Dankjewel Jan, jullie waren fantastische gasten!')
            ->assertSee('Reactie van');
    }

    public function test_admin_review_overview_renders(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);

        $this->actingAs($admin)
            ->get('/admin/reviews')
            ->assertOk();
    }
}
