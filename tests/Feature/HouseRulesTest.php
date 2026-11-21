<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Enums\MailType;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The house rules: fixed rules (check-in, guests, pets, smoking, parties,
 * photography, quiet hours, the doors) as lines with an icon, then the
 * owner's own text. On the accommodation page, in the conditions the guest
 * accepts at checkout (stored with the booking), in the mails and the PDF.
 */
class HouseRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, ...$extra,
        ]);
    }

    /** All fixed rules set on the default screen. */
    private function allRulesInTheDefault(): void
    {
        Property::master()->update([
            'check_in_from' => '15:00', 'check_in_until' => '22:00', 'check_out_until' => '11:00',
            'pets_allowed' => false, 'smoking' => Property::SMOKING_NO, 'events_allowed' => false,
            'commercial_photography_allowed' => false, 'quiet_hours_from' => '22:00', 'quiet_hours_until' => '08:00',
            'lock_doors_on_departure' => true,
        ]);
    }

    public function test_the_house_rules_are_lines_with_an_icon_in_a_fixed_order(): void
    {
        $this->allRulesInTheDefault();

        $rules = $this->accommodation()->houseRuleLines();

        $this->assertSame(
            ['log-in', 'log-out', 'users', 'paw-off', 'cigarette-off', 'musical-note-off', 'camera-off', 'moon', 'key'],
            array_column($rules, 'icon'),
        );
        $this->assertSame([
            __('Check-in from :from until :until', ['from' => '15:00', 'until' => '22:00']),
            __('Check-out: :time', ['time' => '11:00']),
            __('At most :count guests', ['count' => 4]),
            __('No pets'),
            __('No smoking'),
            __('No parties or events'),
            __('No commercial photography'),
            __('Quiet hours from :from until :until', ['from' => '22:00', 'until' => '08:00']),
            __('Lock the doors when you leave'),
        ], array_column($rules, 'text'));
        $this->assertSame(__('Including vaping and e-cigarettes'), $rules[4]['note']);
    }

    public function test_an_accommodation_follows_the_default_unless_it_chooses_itself(): void
    {
        Property::master()->update(['smoking' => Property::SMOKING_NO]);
        $following = $this->accommodation();
        $own = $this->accommodation(['slug' => 'city-flat', 'smoking' => Property::SMOKING_OUTSIDE, 'pets_allowed' => true, 'max_pets' => 1]);

        // A rule nobody set has no line: nothing is made up.
        $this->assertSame(
            [__('At most :count guests', ['count' => 4]), __('No pets'), __('No smoking')],
            array_column($following->houseRuleLines(), 'text'),
        );

        $this->assertSame(
            [__('At most :count guests', ['count' => 4]), __('Pets allowed (at most :count)', ['count' => 1]), __('Smoking only outside')],
            array_column($own->houseRuleLines(), 'text'),
        );
    }

    public function test_the_rules_are_in_the_conditions_at_checkout_and_stored_with_the_booking(): void
    {
        $this->allRulesInTheDefault();
        $property = $this->accommodation(['house_rules' => 'Shoes off inside.']);
        $dates = ['arrival' => today()->addDays(30)->toDateString(), 'departure' => today()->addDays(33)->toDateString(), 'guests' => 2];

        // "Read the conditions" at checkout.
        $this->get(route('bookings.create', ['property' => $property->slug, ...$dates]))->assertOk()
            ->assertSeeInOrder([__('Conditions'), '- '.__('No smoking'), '- '.__('No parties or events'), '- '.__('Lock the doors when you leave'), 'Shoes off inside.']);

        $this->post(route('bookings.store', $property->slug), ['name' => 'Jan', 'email' => 'jan@example.com', 'terms' => '1', ...$dates])
            ->assertSessionHasNoErrors();

        // What the guest accepted is stored as proof.
        $snapshot = Booking::sole()->terms_snapshot;
        $this->assertStringContainsString('- '.__('No smoking').' ('.__('Including vaping and e-cigarettes').')', $snapshot);
        $this->assertStringContainsString('- '.__('Quiet hours from :from until :until', ['from' => '22:00', 'until' => '08:00']), $snapshot);
        $this->assertStringContainsString('Shoes off inside.', $snapshot);
    }

    public function test_the_accommodation_page_shows_the_rules_with_their_icon(): void
    {
        $this->allRulesInTheDefault();
        $property = $this->accommodation(['house_rules' => 'Shoes off inside.']);

        $this->get('/'.$property->slug)->assertOk()
            ->assertSeeInOrder([__('House rules'), __('No smoking'), __('Including vaping and e-cigarettes'), __('Lock the doors when you leave'), 'Shoes off inside.', __('Cancellation policy')]);
    }

    public function test_the_own_rules_are_lines_in_the_same_list(): void
    {
        $property = $this->accommodation(['house_rules' => "- Shoes off inside.\n\n• No glass by the pool.\r\nKeys in the box"]);

        // One per line: no empty lines, no bullet the owner typed.
        $this->assertSame(['Shoes off inside.', 'No glass by the pool.', 'Keys in the box'], $property->ownHouseRules());
        $this->assertStringEndsWith("- Shoes off inside.\n- No glass by the pool.\n- Keys in the box", $property->houseRulesText());

        // On the page each one is a line with an icon, like the fixed rules.
        $html = $this->get('/'.$property->slug)->assertOk()->getContent();
        foreach (['Shoes off inside.', 'No glass by the pool.', 'Keys in the box'] as $rule) {
            $this->assertMatchesRegularExpression('#<li class="flex items-start gap-3">\s*<svg[^>]*>.*?</svg>\s*<span>\s*'.preg_quote($rule, '#').'#s', $html);
        }
    }

    public function test_the_check_in_mail_has_the_rules_without_the_times_twice(): void
    {
        $this->allRulesInTheDefault();
        $booking = app(BookingEngine::class)->create(
            $this->accommodation(),
            CarbonImmutable::today()->addDays(30),
            CarbonImmutable::today()->addDays(33),
            2,
            ['name' => 'Jan', 'email' => 'jan@example.com'],
        );

        $html = (new BookingMail($booking, MailType::Checkin))->locale('en')->render();

        $this->assertStringContainsString(__('No smoking', [], 'en'), $html);
        $this->assertStringContainsString(__('Lock the doors when you leave', [], 'en'), $html);
        // The times are above the rules already: once, not again in the rules.
        $this->assertSame(1, substr_count($html, __('Check-out: :time', ['time' => '11:00'], 'en')));
        $this->assertStringNotContainsString(__('Check-in from :from until :until', ['from' => '15:00', 'until' => '22:00'], 'en'), $html);
    }

    public function test_a_rule_that_does_not_allow_something_has_its_icon_crossed_out(): void
    {
        $this->blade('<x-site-icon name="paw-off" />')->assertSee('d="m3 3 18 18"', false);
        $this->blade('<x-site-icon name="paw" />')->assertDontSee('d="m3 3 18 18"', false);
    }

    public function test_the_owner_sets_the_house_rules_in_the_admin(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($owner)
            ->test(EditDefaults::class)
            ->fillForm([
                'smoking' => Property::SMOKING_NO, 'events_allowed' => '0', 'commercial_photography_allowed' => '0',
                'quiet_hours_from' => '22:00', 'quiet_hours_until' => '07:00', 'lock_doors_on_departure' => '1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $default = Property::master();
        $this->assertSame(Property::SMOKING_NO, $default->smoking);
        $this->assertNotNull($default->events_allowed);
        $this->assertFalse((bool) $default->events_allowed);
        $this->assertTrue((bool) $default->lock_doors_on_departure);

        $accommodation = $this->accommodation();
        $page = Livewire::actingAs($owner)->test(EditProperty::class, ['record' => $accommodation->getKey()]);

        // Quiet hours need a start and an end.
        $page->fillForm(['quiet_hours_from' => '23:00'])->call('save')->assertHasFormErrors(['quiet_hours_until']);

        $page->fillForm(['quiet_hours_from' => null, 'smoking' => Property::SMOKING_OUTSIDE, 'lock_doors_on_departure' => '0'])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame(Property::SMOKING_OUTSIDE, $accommodation->smoking);
        // Not chosen here: follows the default.
        $this->assertNull($accommodation->events_allowed);

        $texts = array_column($accommodation->houseRuleLines(), 'text');
        $this->assertContains(__('Smoking only outside'), $texts);
        $this->assertContains(__('No parties or events'), $texts);
        $this->assertNotContains(__('Lock the doors when you leave'), $texts);
    }
}
