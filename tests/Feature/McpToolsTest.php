<?php

namespace Tests\Feature;

use App\Engines\PricingEngine;
use App\Enums\BookingStatus;
use App\Enums\CalendarBlockType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Mcp\Servers\BookingServer;
use App\Mcp\Tools\ApplyStyleTemplate;
use App\Mcp\Tools\BlockDates;
use App\Mcp\Tools\ChooseLayout;
use App\Mcp\Tools\GetAccommodation;
use App\Mcp\Tools\GetBooking;
use App\Mcp\Tools\GetCalendar;
use App\Mcp\Tools\GetPriceQuote;
use App\Mcp\Tools\GetSiteStyle;
use App\Mcp\Tools\GetStatistics;
use App\Mcp\Tools\ListAccommodations;
use App\Mcp\Tools\ListBookings;
use App\Mcp\Tools\RemoveDateBlock;
use App\Mcp\Tools\SetStyleRule;
use App\Mcp\Tools\UndoStyleChange;
use App\Mcp\Tools\UpdateAccommodationPrices;
use App\Mcp\Tools\UpdateAccommodationText;
use App\Mcp\Tools\UpdatePageText;
use App\Mcp\Tools\UpdateStyle;
use App\Models\Booking;
use App\Models\CalendarBlock;
use App\Models\Host;
use App\Models\ListingEvent;
use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use App\Support\SiteTemplates;
use App\Support\VisualStyle;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The actions an AI app can take through the AI connection: what they
 * show and change, and that every person only gets what their rights in the
 * admin allow.
 */
class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    private Property $beach;

    private Property $cabin;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->beach = $this->accommodation('Beach house', 'beach-house', 10000);
        $this->cabin = $this->accommodation('Jungle cabin', 'jungle-cabin', 8000);

        // The host only has the cabin.
        $this->host = Host::create(['name' => 'Ana']);
        $this->cabin->hosts()->sync([$this->host->id]);
    }

    private function accommodation(string $name, string $slug, int $price): Property
    {
        return Property::create([
            'name' => $name, 'slug' => $slug, 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => $price, 'currency' => 'EUR',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function hostUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $this->host->id,
            'permissions' => ['accommodations' => Access::ACTIONS, 'bookings' => [Access::VIEW], 'calendar' => Access::ACTIONS],
        ]);
    }

    private function booking(Property $property, string $number, array $fields = []): Booking
    {
        return Booking::create([
            'booking_number' => $number, 'property_id' => $property->id,
            'status' => BookingStatus::Confirmed, 'payment_status' => PaymentStatus::Unpaid,
            'guest_name' => 'Jan de Vries', 'guest_email' => 'jan@example.com', 'guest_phone' => '+31 6 1234 5678',
            'arrival_date' => today()->addDays(30)->toDateString(), 'departure_date' => today()->addDays(33)->toDateString(),
            'guests' => 2, 'total_cents' => 30000, 'down_payment_cents' => 9000, 'currency' => 'EUR',
            'price_snapshot' => ['lines' => [['label' => '3 nights', 'amount_cents' => 30000]]], 'locale' => 'en',
            ...$fields,
        ]);
    }

    // --- Rights -----------------------------------------------------------

    public function test_every_person_only_gets_the_tools_their_rights_allow(): void
    {
        BookingServer::actingAs($this->owner())->tools()
            ->assertRegistered([ListAccommodations::class, ListBookings::class, GetSiteStyle::class, UpdateStyle::class, UpdateAccommodationPrices::class]);

        // A host: their accommodations, bookings and calendar, but not the look of the site or the statistics.
        BookingServer::actingAs($this->hostUser())->tools()
            ->assertRegistered([ListAccommodations::class, UpdateAccommodationPrices::class, BlockDates::class])
            ->assertNotRegistered([GetSiteStyle::class, UpdateStyle::class, GetStatistics::class, UpdatePageText::class]);

        // A staff member who may only look at bookings.
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['bookings' => [Access::VIEW]]]);
        BookingServer::actingAs($staff)->tools()
            ->assertRegistered([ListBookings::class, GetBooking::class])
            ->assertNotRegistered([ListAccommodations::class, UpdateAccommodationPrices::class, BlockDates::class, ApplyStyleTemplate::class]);

        // And cannot call a tool they do not see.
        BookingServer::actingAs($staff)->tool(UpdateAccommodationPrices::class, ['accommodation' => $this->beach->id, 'base_price' => 1])
            ->assertHasErrors();
        $this->assertSame(10000, $this->beach->refresh()->base_price_cents);
    }

    public function test_a_host_only_finds_and_changes_their_own_accommodation(): void
    {
        $host = $this->hostUser();

        BookingServer::actingAs($host)->tool(ListAccommodations::class)
            ->assertOk()->assertSee('Jungle cabin')->assertDontSee('Beach house');

        BookingServer::actingAs($host)->tool(GetAccommodation::class, ['accommodation' => $this->beach->id])
            ->assertHasErrors(['No accommodation']);

        BookingServer::actingAs($host)->tool(UpdateAccommodationPrices::class, ['accommodation' => 'beach-house', 'base_price' => 1])
            ->assertHasErrors(['No accommodation']);
        $this->assertSame(10000, $this->beach->refresh()->base_price_cents);

        BookingServer::actingAs($host)->tool(UpdateAccommodationPrices::class, ['accommodation' => 'Jungle cabin', 'base_price' => 95.5])
            ->assertOk();
        $this->assertSame(9550, $this->cabin->refresh()->base_price_cents);
    }

    // --- Reading ----------------------------------------------------------

    public function test_an_accommodation_shows_its_own_and_its_applying_prices(): void
    {
        Property::master()->update(['cleaning_fee_cents' => 4000]);

        BookingServer::actingAs($this->owner())->tool(GetAccommodation::class, ['accommodation' => $this->beach->id])
            ->assertOk()
            ->assertStructuredContentPath('prices.base_price', ['own' => 100, 'applies' => 100])
            // No cleaning fee of its own: the default applies.
            ->assertStructuredContentPath('prices.cleaning_fee', ['own' => null, 'applies' => 40])
            ->assertStructuredContentPath('texts.name.en', 'Beach house');
    }

    public function test_the_price_quote_is_the_one_a_guest_gets(): void
    {
        $arrival = today()->addDays(60);

        BookingServer::actingAs($this->owner())->tool(GetPriceQuote::class, [
            'accommodation' => $this->beach->id,
            'arrival' => $arrival->toDateString(), 'departure' => $arrival->copy()->addDays(3)->toDateString(), 'guests' => 2,
        ])
            ->assertOk()
            ->assertStructuredContentPath('available', true)
            ->assertStructuredContentPath('nights', 3)
            ->assertStructuredContentPath('total', app(PricingEngine::class)->quote(
                $this->beach, $arrival->toImmutable(), $arrival->toImmutable()->addDays(3), 2,
            )->totalCents / 100);
    }

    public function test_bookings_are_listed_within_the_persons_rights_with_details(): void
    {
        $this->booking($this->beach, 'BK-BEACH');
        $this->booking($this->cabin, 'BK-CABIN', ['guest_name' => 'Maria']);

        BookingServer::actingAs($this->owner())->tool(ListBookings::class)
            ->assertOk()->assertSee(['BK-BEACH', 'BK-CABIN']);

        BookingServer::actingAs($this->hostUser())->tool(ListBookings::class)
            ->assertOk()->assertSee('BK-CABIN')->assertDontSee('BK-BEACH');

        BookingServer::actingAs($this->hostUser())->tool(GetBooking::class, ['booking_number' => 'BK-BEACH'])
            ->assertHasErrors(['No booking']);

        BookingServer::actingAs($this->owner())->tool(GetBooking::class, ['booking_number' => 'BK-CABIN'])
            ->assertOk()
            ->assertStructuredContentPath('guest.name', 'Maria')
            ->assertStructuredContentPath('total', 300)
            ->assertStructuredContentPath('outstanding', 300);
    }

    public function test_the_calendar_shows_taken_nights_blocks_and_bookings(): void
    {
        $booking = $this->booking($this->beach, 'BK-CAL');
        CalendarBlock::create(['property_id' => $this->beach->id, 'type' => CalendarBlockType::Blocked, 'start_date' => today()->addDays(40), 'end_date' => today()->addDays(41)]);

        BookingServer::actingAs($this->owner())->tool(GetCalendar::class, ['accommodation' => $this->beach->id])
            ->assertOk()
            ->assertSee([$booking->arrival_date->toDateString(), today()->addDays(40)->toDateString(), 'BK-CAL']);
    }

    public function test_the_statistics_are_the_same_figures_as_the_statistics_page(): void
    {
        foreach ([$this->beach, $this->beach, $this->cabin] as $property) {
            ListingEvent::record('page_view', $property);
        }
        ListingEvent::record('booking', $this->beach);

        BookingServer::actingAs($this->owner())->tool(GetStatistics::class)
            ->assertOk()->assertStructuredContentPath('views', 3)->assertStructuredContentPath('bookings', 1);

        BookingServer::actingAs($this->owner())->tool(GetStatistics::class, ['accommodation' => 'jungle-cabin'])
            ->assertOk()->assertStructuredContentPath('views', 1)->assertStructuredContentPath('bookings', 0);
    }

    // --- The look of the website (owner) ----------------------------------

    public function test_the_owner_applies_a_style_template_and_can_undo_it(): void
    {
        Setting::set('frontend', 'accent_color', '#111111');
        $key = array_key_last(SiteTemplates::all());
        $tokens = SiteTemplates::cleanTokens(SiteTemplates::all()[$key]['tokens']);

        BookingServer::actingAs($this->owner())->tool(ApplyStyleTemplate::class, ['template' => $key])->assertOk();

        $this->assertSame($key, Setting::get('frontend', 'template_name'));
        $this->assertSame($tokens['accent_color'], Setting::get('frontend', 'accent_color'));

        BookingServer::actingAs($this->owner())->tool(UndoStyleChange::class)->assertOk()->assertSee('Undone');

        $this->assertSame('#111111', Setting::get('frontend', 'accent_color'));
        $this->assertNull(Setting::get('frontend', 'template_name'));

        // Nothing left to undo.
        BookingServer::actingAs($this->owner())->tool(UndoStyleChange::class)->assertHasErrors(['left to undo']);
    }

    public function test_the_owner_can_also_undo_it_in_the_admin(): void
    {
        $owner = $this->owner();
        BookingServer::actingAs($owner)->tool(UpdateStyle::class, ['accent_color' => '#155e75'])->assertOk();

        Livewire::actingAs($owner)->test(Settings::class)
            ->assertActionVisible(TestAction::make('undoMcpStyle')->schemaComponent('style_editor_actions', schema: 'form'))
            ->callAction(TestAction::make('undoMcpStyle')->schemaComponent('style_editor_actions', schema: 'form'))
            ->assertSet('data.frontend_accent_color', null)
            ->assertActionDoesNotExist(TestAction::make('undoMcpStyle')->schemaComponent('style_editor_actions', schema: 'form'));

        $this->assertNull(Setting::get('frontend', 'accent_color'));
    }

    public function test_style_settings_only_take_allowed_values(): void
    {
        BookingServer::actingAs($this->owner())->tool(UpdateStyle::class, ['accent_color' => 'red;}</style><script>', 'font_style' => 'serif'])
            ->assertHasErrors(['accent_color']);
        $this->assertNull(Setting::get('frontend', 'font_style'));

        BookingServer::actingAs($this->owner())->tool(UpdateStyle::class, ['accent_color' => '#155e75', 'font_style' => 'serif'])->assertOk();
        $this->assertSame('#155e75', Setting::get('frontend', 'accent_color'));
        $this->assertSame('serif', Setting::get('frontend', 'font_style'));

        BookingServer::actingAs($this->owner())->tool(UpdateStyle::class, ['accent_color' => 'default'])->assertOk();
        $this->assertNull(Setting::get('frontend', 'accent_color'));
    }

    public function test_style_rules_are_sanitised_like_the_style_editor(): void
    {
        $owner = $this->owner();

        // Not on the list, or able to load something from elsewhere: refused, nothing saved.
        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1', 'declarations' => ['position' => 'fixed']])
            ->assertHasErrors(['position']);
        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1', 'declarations' => ['background-color' => 'url(https://evil.example/x.png)']])
            ->assertHasErrors(['background-color']);
        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1{}</style>', 'declarations' => ['color' => 'red']])
            ->assertHasErrors(['selector']);
        $this->assertSame([], VisualStyle::rules());

        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1', 'declarations' => ['font-size' => '3rem', 'color' => '#1c1917']])->assertOk();
        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1', 'declarations' => ['color' => '']])->assertOk();
        $this->assertSame([['selector' => 'h1', 'declarations' => ['font-size' => '3rem']]], VisualStyle::rules());

        BookingServer::actingAs($owner)->tool(SetStyleRule::class, ['selector' => 'h1', 'remove' => true])->assertOk();
        $this->assertSame([], VisualStyle::rules());
    }

    public function test_only_installed_layouts_can_be_chosen(): void
    {
        BookingServer::actingAs($this->owner())->tool(ChooseLayout::class, ['layout' => 'fullscreen'])->assertOk();
        $this->assertSame('fullscreen', Setting::get('frontend', 'layout'));

        BookingServer::actingAs($this->owner())->tool(ChooseLayout::class, ['layout' => '../../etc'])->assertHasErrors();
        $this->assertSame('fullscreen', Setting::get('frontend', 'layout'));
    }

    // --- Content ----------------------------------------------------------

    public function test_texts_change_per_language_without_html_of_their_own(): void
    {
        $owner = $this->owner();

        BookingServer::actingAs($owner)->tool(UpdateAccommodationText::class, [
            'accommodation' => $this->beach->id, 'field' => 'description', 'language' => 'en',
            'text' => "Right on the **beach**.\n\n<script>alert(1)</script>\n\n![x](https://evil.example/p.png) [Map](javascript:alert(1))",
        ])->assertOk();

        $description = $this->beach->refresh()->description;
        $this->assertStringContainsString('<strong>beach</strong>', $description);
        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('<img', $description);
        $this->assertStringNotContainsString('javascript:', $description);

        BookingServer::actingAs($owner)->tool(UpdateAccommodationText::class, [
            'accommodation' => $this->beach->id, 'field' => 'name', 'language' => 'nl', 'text' => 'Strandhuis',
        ])->assertOk();
        $this->assertSame('Strandhuis', $this->beach->refresh()->name_translations['nl']);
        $this->assertSame('beach-house', $this->beach->slug);

        // Empty in another language: back to the source text.
        BookingServer::actingAs($owner)->tool(UpdateAccommodationText::class, [
            'accommodation' => $this->beach->id, 'field' => 'name', 'language' => 'nl', 'text' => '',
        ])->assertOk();
        $this->assertArrayNotHasKey('nl', $this->beach->refresh()->name_translations ?? []);

        BookingServer::actingAs($owner)->tool(UpdateAccommodationText::class, [
            'accommodation' => $this->beach->id, 'field' => 'name', 'language' => 'en', 'text' => '',
        ])->assertHasErrors(['cannot be empty']);
    }

    public function test_a_text_with_photos_or_videos_is_left_to_the_admin(): void
    {
        $this->beach->update(['description' => '<p>Look:</p><img src="/storage/1/photo.jpg">']);

        BookingServer::actingAs($this->owner())->tool(UpdateAccommodationText::class, [
            'accommodation' => $this->beach->id, 'field' => 'description', 'language' => 'en', 'text' => 'New text',
        ])->assertHasErrors(['photos or videos']);

        $this->assertStringContainsString('<img', $this->beach->refresh()->description);
    }

    public function test_prices_change_follow_the_default_again_and_need_a_complete_weekend_rule(): void
    {
        $owner = $this->owner();

        BookingServer::actingAs($owner)->tool(UpdateAccommodationPrices::class, [
            'accommodation' => $this->beach->id, 'cleaning_fee' => 45, 'tourist_tax_basis' => Property::TAX_PER_STAY,
        ])->assertOk();
        $this->beach->refresh();
        $this->assertSame(4500, $this->beach->cleaning_fee_cents);
        $this->assertSame(Property::TAX_PER_STAY, $this->beach->tourist_tax_basis);

        BookingServer::actingAs($owner)->tool(UpdateAccommodationPrices::class, [
            'accommodation' => $this->beach->id, 'reset' => ['cleaning_fee'],
        ])->assertOk();
        $this->assertNull($this->beach->refresh()->cleaning_fee_cents);

        BookingServer::actingAs($owner)->tool(UpdateAccommodationPrices::class, [
            'accommodation' => $this->beach->id, 'weekend_kind' => Property::WEEKEND_PERCENT,
        ])->assertHasErrors(['weekend_percentage']);
        $this->assertNull($this->beach->refresh()->weekend_kind);

        BookingServer::actingAs($owner)->tool(UpdateAccommodationPrices::class, [
            'accommodation' => $this->beach->id, 'weekend_kind' => Property::WEEKEND_PERCENT, 'weekend_percentage' => 15,
        ])->assertOk();
        $this->assertSame(15, (int) $this->beach->refresh()->weekend_percentage);
    }

    public function test_changes_are_logged_as_made_by_the_person(): void
    {
        $owner = $this->owner();

        BookingServer::actingAs($owner)->tool(UpdateAccommodationPrices::class, ['accommodation' => $this->beach->id, 'base_price' => 120])->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $this->beach->getMorphClass(), 'subject_id' => $this->beach->id,
            'causer_type' => $owner->getMorphClass(), 'causer_id' => $owner->id,
        ]);
    }

    public function test_dates_are_blocked_and_freed_but_not_synchronised_blocks(): void
    {
        $owner = $this->owner();
        $booking = $this->booking($this->beach, 'BK-OVERLAP');

        BookingServer::actingAs($owner)->tool(BlockDates::class, [
            'accommodation' => $this->beach->id,
            'first_night' => $booking->arrival_date->toDateString(), 'last_night' => $booking->arrival_date->addDay()->toDateString(), 'note' => 'Painting',
        ])->assertOk()->assertStructuredContentPath('overlapping_bookings', ['BK-OVERLAP']);

        $block = CalendarBlock::query()->latest('id')->first();
        $this->assertSame('Painting', $block->note);

        BookingServer::actingAs($owner)->tool(BlockDates::class, [
            'accommodation' => $this->beach->id, 'first_night' => '2030-06-12', 'last_night' => '2030-06-10',
        ])->assertHasErrors();

        BookingServer::actingAs($owner)->tool(RemoveDateBlock::class, ['block_id' => $block->id])->assertOk();
        $this->assertModelMissing($block);

        $synced = CalendarBlock::create(['property_id' => $this->beach->id, 'type' => CalendarBlockType::External, 'source' => 'airbnb', 'external_uid' => 'x', 'start_date' => '2030-01-01', 'end_date' => '2030-01-02']);
        BookingServer::actingAs($owner)->tool(RemoveDateBlock::class, ['block_id' => $synced->id])->assertHasErrors(['synchronisation']);
        $this->assertModelExists($synced);

        // A host cannot remove a block of someone else's accommodation.
        $foreign = CalendarBlock::create(['property_id' => $this->beach->id, 'type' => CalendarBlockType::Blocked, 'start_date' => '2030-02-01', 'end_date' => '2030-02-02']);
        BookingServer::actingAs($this->hostUser())->tool(RemoveDateBlock::class, ['block_id' => $foreign->id])->assertHasErrors(['No block']);
        $this->assertModelExists($foreign);
    }

    public function test_page_texts_change_per_language(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about-us', 'content' => '<p>Old</p>', 'published' => true]);

        BookingServer::actingAs($this->owner())->tool(UpdatePageText::class, [
            'page_id' => $page->id, 'field' => 'text', 'language' => 'en', 'text' => 'We are **Ana** and Luis.',
        ])->assertOk();

        $this->assertStringContainsString('<strong>Ana</strong>', $page->refresh()->content);
        $this->assertSame('about-us', $page->slug);
    }
}
