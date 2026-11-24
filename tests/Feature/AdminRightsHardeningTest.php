<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Filament\Widgets\RevenueChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TodayAgenda;
use App\Livewire\NightlyPricesCalendar;
use App\Models\AiImage;
use App\Models\Booking;
use App\Models\Host;
use App\Models\Property;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rights in the admin that were only checked by hiding a button, or not at
 * all: what someone may only look at, they cannot change, and a host only
 * reaches their own accommodations.
 */
class AdminRightsHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private Property $cabin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->beach = $this->accommodation('Beach house', 'beach-house');
        $this->cabin = $this->accommodation('Jungle cabin', 'jungle-cabin');
        $this->host = Host::create(['name' => 'Ana']);
        $this->cabin->hosts()->sync([$this->host->id]);
    }

    private function accommodation(string $name, string $slug): Property
    {
        return Property::create(['name' => $name, 'slug' => $slug, 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true, 'base_price_cents' => 10000]);
    }

    private function staff(array $permissions): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'permissions' => $permissions]);
    }

    private function booking(Property $property): Booking
    {
        return Booking::create([
            'booking_number' => 'BK-'.uniqid(), 'property_id' => $property->id,
            'status' => 'confirmed', 'payment_status' => 'unpaid',
            'guest_name' => 'Jan', 'guest_email' => 'jan@example.com',
            'arrival_date' => today()->addDays(10)->toDateString(), 'departure_date' => today()->addDays(12)->toDateString(), 'guests' => 2,
            'total_cents' => 20000, 'down_payment_cents' => 6000, 'price_snapshot' => ['lines' => []],
        ]);
    }

    public function test_a_host_with_the_media_right_only_reaches_their_own_accommodation_photos(): void
    {
        $theirs = $this->beach->addPhoto(UploadedFile::fake()->image('beach.jpg'));
        $own = $this->cabin->addPhoto(UploadedFile::fake()->image('cabin.jpg'));
        $host = User::factory()->create(['role' => UserRole::Host, 'host_id' => $this->host->id, 'permissions' => ['media' => [Access::EDIT]]]);

        $folders = $this->actingAs($host)->getJson('/admin-media/list?path=accommodations')->assertOk()->json('dirs');
        $this->assertSame(['Jungle cabin'], array_column($folders, 'name'));

        $this->actingAs($host)->getJson("/admin-media/list?path=accommodations/{$this->beach->id}")->assertNotFound();
        $this->actingAs($host)->postJson('/admin-media/delete', ['path' => "accommodations/{$this->beach->id}/{$theirs->id}", 'type' => 'file'])->assertNotFound();
        $this->assertModelExists($theirs);

        $this->actingAs($host)->getJson("/admin-media/list?path=accommodations/{$this->cabin->id}")->assertOk()->assertJsonCount(1, 'files');
        $this->assertModelExists($own);
    }

    public function test_reviews_can_only_be_approved_or_answered_with_the_right_to_edit(): void
    {
        $review = Review::create([
            'booking_id' => $this->booking($this->beach)->id, 'property_id' => $this->beach->id,
            'guest_name' => 'Jan', 'rating' => 2, 'comment' => 'Meh', 'status' => 'rejected',
        ]);

        Livewire::actingAs($this->staff(['reviews' => [Access::VIEW]]))->test(ManageReviews::class)
            ->assertActionHidden(TestAction::make('approve')->table($review))
            ->assertActionHidden(TestAction::make('answer')->table($review));

        $this->assertSame('rejected', $review->refresh()->status->value);

        Livewire::actingAs($this->staff(['reviews' => [Access::VIEW, Access::EDIT]]))->test(ManageReviews::class)
            ->callAction(TestAction::make('approve')->table($review));

        $this->assertSame('approved', $review->refresh()->status->value);
    }

    public function test_the_dashboard_figures_follow_the_rights_for_payments_and_bookings(): void
    {
        $this->actingAs($this->staff(['bookings' => [Access::VIEW]]));
        $this->assertFalse(StatsOverview::canView());
        $this->assertFalse(RevenueChart::canView());
        $this->assertTrue(TodayAgenda::canView());

        $this->actingAs($this->staff(['payments' => [Access::VIEW]]));
        $this->assertTrue(StatsOverview::canView());
        $this->assertFalse(TodayAgenda::canView());
    }

    public function test_importing_a_listing_needs_the_right_to_create_accommodations(): void
    {
        Livewire::actingAs($this->staff(['accommodations' => [Access::VIEW]]))->test(ListProperties::class)
            ->assertActionHidden('import');

        Livewire::actingAs($this->staff(['accommodations' => [Access::VIEW, Access::CREATE]]))->test(ListProperties::class)
            ->assertActionVisible('import');
    }

    public function test_translating_a_whole_list_needs_the_right_to_edit_it(): void
    {
        Livewire::actingAs($this->staff(['amenities' => [Access::VIEW]]))->test(ManageAmenities::class)
            ->assertActionHidden(TestAction::make('translate')->table());

        Livewire::actingAs($this->staff(['amenities' => [Access::VIEW, Access::EDIT]]))->test(ManageAmenities::class)
            ->assertActionVisible(TestAction::make('translate')->table());
    }

    public function test_looking_at_a_booking_does_not_create_its_invoice(): void
    {
        $booking = $this->booking($this->beach);

        Livewire::actingAs($this->staff(['bookings' => [Access::VIEW]]))->test(ListBookings::class)
            ->assertActionHidden(TestAction::make('invoice')->table($booking));

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_prices_cannot_be_changed_without_the_right_to_edit_the_accommodation(): void
    {
        $host = User::factory()->create(['role' => UserRole::Host, 'host_id' => $this->host->id, 'permissions' => ['accommodations' => Access::ACTIONS]]);
        $date = today()->addDays(20)->toDateString();

        // The beach house is not theirs.
        Livewire::actingAs($host)->test(NightlyPricesCalendar::class, ['property' => $this->beach])
            ->set('selected', [$date])
            ->set('priceInput', '1')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseMissing('nightly_rates', ['property_id' => $this->beach->id]);
    }

    public function test_blocking_and_freeing_nights_needs_the_calendar_right(): void
    {
        $date = today()->addDays(20)->toDateString();
        $editorOnly = User::factory()->create(['role' => UserRole::Host, 'host_id' => $this->host->id, 'permissions' => ['accommodations' => [Access::VIEW, Access::EDIT]]]);
        $this->cabin->calendarBlocks()->create(['type' => 'blocked', 'start_date' => today()->addDays(30), 'end_date' => today()->addDays(30)]);

        // Editing the accommodation (prices) is allowed; blocking is not, and the buttons are not there.
        Livewire::actingAs($editorOnly)->test(NightlyPricesCalendar::class, ['property' => $this->cabin])
            ->assertDontSee(__('Unblock'))
            ->set('selected', [$date])
            ->call('block')
            ->assertForbidden();
        Livewire::actingAs($editorOnly)->test(NightlyPricesCalendar::class, ['property' => $this->cabin])
            ->set('selected', [today()->addDays(30)->toDateString()])
            ->call('unblock')
            ->assertForbidden();
        $this->assertSame(1, $this->cabin->calendarBlocks()->count());

        // With the Calendar right it works.
        $withCalendar = User::factory()->create(['role' => UserRole::Host, 'host_id' => $this->host->id, 'permissions' => ['accommodations' => [Access::VIEW, Access::EDIT], 'calendar' => Access::ACTIONS]]);
        Livewire::actingAs($withCalendar)->test(NightlyPricesCalendar::class, ['property' => $this->cabin])
            ->assertSee(__('Unblock'))
            ->set('selected', [$date])
            ->call('block')
            ->assertOk();
        $this->assertSame(2, $this->cabin->calendarBlocks()->count());
    }

    public function test_the_media_manager_also_asks_for_two_step_verification_when_required(): void
    {
        Setting::set('security', 'two_step_required', '1');
        $owner = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($owner)->getJson('/admin-media/list')->assertRedirect();
        $this->actingAs($owner)->post('/admin-media/upload', ['path' => '', 'files' => [UploadedFile::fake()->image('a.jpg')]])->assertRedirect();
        Storage::disk('public')->assertMissing('media/a.jpg');
    }

    public function test_an_approved_ai_image_is_not_deleted_through_the_queue(): void
    {
        Storage::disk('public')->put('media/ai/sunset.png', 'x');
        $image = AiImage::create(['prompt' => 'Sunset', 'status' => 'approved', 'path' => 'media/ai/sunset.png', 'month' => now()->format('Y-m')]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->postJson("/admin-media/ai/{$image->id}/reject")->assertStatus(422);

        Storage::disk('public')->assertExists('media/ai/sunset.png');
    }
}
