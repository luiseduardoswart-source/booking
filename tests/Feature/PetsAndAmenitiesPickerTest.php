<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Amenity;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pets are a rule (default and per property), and amenities are chosen with
 * a button per amenity, which saves immediately for an existing property.
 */
class PetsAndAmenitiesPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', ...$extra,
        ]);
    }

    private function book(Property $accommodation, int $pets): \Illuminate\Testing\TestResponse
    {
        return $this->post("/{$accommodation->slug}/book", [
            'arrival' => now()->addMonth()->toDateString(),
            'departure' => now()->addMonth()->addDays(3)->toDateString(),
            'guests' => 2, 'adults' => 2, 'pets' => $pets,
            'name' => 'Jan Jansen', 'email' => 'jan@example.com', 'terms' => '1',
        ]);
    }

    public function test_a_accommodation_follows_the_default_or_chooses_itself(): void
    {
        Property::master()->update(['pets_allowed' => false]);
        $follows = $this->accommodation();
        $welcome = $this->accommodation(['pets_allowed' => true]);

        $this->assertFalse($follows->petsAllowed());
        $this->assertTrue($welcome->petsAllowed());

        Property::master()->update(['pets_allowed' => true]);
        $this->assertTrue($follows->fresh()->petsAllowed());
        $this->assertFalse($this->accommodation(['pets_allowed' => false])->petsAllowed());

        $this->withSession(['locale' => 'nl'])->get("/{$welcome->slug}")->assertOk()->assertSee('data-pets="1"', false);
    }

    public function test_the_maximum_number_of_pets_is_only_asked_when_pets_are_allowed(): void
    {
        Property::master()->update(['pets_allowed' => false]);

        // Defaults screen: "No" hides the maximum, "Yes" shows it right away.
        Livewire::actingAs($owner = $this->owner())
            ->test(EditDefaults::class)
            ->assertFormFieldHidden('max_pets')
            ->fillForm(['pets_allowed' => '1'])
            ->assertFormFieldVisible('max_pets');

        // An accommodation that follows the default (No): hidden too.
        Livewire::actingAs($owner)
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertFormFieldHidden('max_pets')
            ->fillForm(['pets_allowed' => '1'])
            ->assertFormFieldVisible('max_pets');
    }

    public function test_an_accommodation_that_follows_a_default_allowing_pets_asks_the_maximum(): void
    {
        Property::master()->update(['pets_allowed' => true]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertFormFieldVisible('max_pets')
            ->fillForm(['pets_allowed' => '0'])
            ->assertFormFieldHidden('max_pets');
    }

    public function test_book_with_pets_is_rejected_as_they_not_may(): void
    {
        Setting::set('site', 'single_accommodation_direct', '0');
        $forbidden = $this->accommodation(['pets_allowed' => false]);

        $this->book($forbidden, 1)->assertSessionHasErrors(['pets' => __('Pets are not allowed.')]);
        $this->assertSame(0, Booking::count());

        $this->withSession(['locale' => 'nl'])->get("/{$forbidden->slug}")->assertSee('data-pets="0"', false);

        $welcome = $this->accommodation(['pets_allowed' => true]);
        $this->book($welcome, 1)->assertSessionHasNoErrors();
        $this->assertSame(1, Booking::where('property_id', $welcome->id)->value('pets'));
    }

    public function test_search_with_pets_shows_only_where_they_welcome_are(): void
    {
        Setting::set('site', 'single_accommodation_direct', '0');
        Property::master()->update(['pets_allowed' => true]);
        $this->accommodation(['name' => 'Hondenhuis', 'pets_allowed' => null]);
        $this->accommodation(['name' => 'Kattenvrij', 'pets_allowed' => false]);

        $this->get('/?pets=1')->assertOk()->assertSee('Hondenhuis')->assertDontSee('Kattenvrij');
        $this->get('/')->assertOk()->assertSee('Kattenvrij');
    }

    public function test_the_amenity_is_the_rule(): void
    {
        // The state from before the rule: pets as an amenity.
        Schema::table('properties', fn ($table) => $table->dropColumn('pets_allowed'));
        $amenity = Amenity::create(['name' => 'Huisdieren toegestaan', 'icon' => 'paw']);
        $with = $this->accommodation();
        $without = $this->accommodation();
        $with->amenities()->attach($amenity->id);

        (require database_path('migrations/2026_11_02_130000_pets_as_rule.php'))->up();

        $this->assertTrue($with->fresh()->petsAllowed());
        $this->assertNull($without->fresh()->pets_allowed);
        $this->assertSame(0, (int) Property::master()->fresh()->pets_allowed);
        $this->assertNull(Amenity::find($amenity->id));
        $this->assertSame(0, DB::table('amenity_property')->where('amenity_id', $amenity->id)->count());
    }

    public function test_the_rule_is_at_the_defaults_and_the_accommodation(): void
    {
        Livewire::actingAs($this->owner());

        // "Off" at the default must also look like Off, not like "Follow the default".
        Property::master()->update(['pets_allowed' => false]);
        Livewire::test(EditDefaults::class)->assertSet('data.pets_allowed', '0');

        Livewire::test(EditProperty::class, ['record' => $this->accommodation()->getRouteKey()])
            ->assertSet('data.pets_allowed', null)
            ->assertSeeHtml('data.pets_allowed')
            ->fillForm(['pets_allowed' => '1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Property::latest('id')->first()->petsAllowed());
    }

    public function test_a_yes_no_question_has_yes_and_no_as_choice(): void
    {
        Livewire::actingAs($this->owner());
        $fields = ['pets_allowed', 'discounts_add_up', 'prices_in_calendar', 'id_required', 'deposit_auto_refund'];

        foreach (['nl' => ['1' => 'Ja', '0' => 'Nee'], 'en' => ['1' => 'Yes', '0' => 'No']] as $language => $expected) {
            app()->setLocale($language);
            $default = Livewire::test(EditDefaults::class);
            $accommodation = Livewire::test(EditProperty::class, ['record' => $this->accommodation()->getRouteKey()]);

            foreach ($fields as $field) {
                $isCorrect = fn (Select $select): bool => $select->getOptions() === $expected;
                $default->assertFormFieldExists($field, $isCorrect);
                $accommodation->assertFormFieldExists($field, $isCorrect);
            }
        }
    }

    public function test_a_amenity_click_saves_immediately_on(): void
    {
        $accommodation = $this->accommodation();
        $wifi = Amenity::firstWhere('name', 'Wifi');
        $sauna = Amenity::firstWhere('name', 'Sauna');

        $page = Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSee(__('Kitchen and dining'))
            ->assertSee(__('Search for an amenity'))
            ->set('data.amenities', [(string) $wifi->id, (string) $sauna->id]);

        $this->assertEqualsCanonicalizing([$wifi->id, $sauna->id], $accommodation->amenities()->pluck('amenities.id')->all());
        Notification::assertNotified(__('Saved'));

        $page->set('data.amenities', [(string) $sauna->id]);
        $this->assertSame([$sauna->id], $accommodation->amenities()->pluck('amenities.id')->all());
    }

    public function test_a_new_amenity_comes_in_the_list_and_is_immediately_on(): void
    {
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->callAction(TestAction::make('newAmenity')->schemaComponent('amenities'), [
                'name' => 'Zeilboot', 'category' => 'outside',
            ])
            ->assertHasNoActionErrors();

        $sailboat = Amenity::firstWhere('name', 'Zeilboot');
        $this->assertSame('outside', $sailboat->resolvedCategory());
        $this->assertSame([$sailboat->id], $accommodation->amenities()->pluck('amenities.id')->all());
    }

    public function test_without_permission_on_amenities_no_new_amenity(): void
    {
        $accommodation = $this->accommodation();
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'permissions' => ['accommodations' => Access::ACTIONS],
        ]);

        Livewire::actingAs($staff)
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->assertSee(__('Search for an amenity'))
            ->assertActionDoesNotExist(TestAction::make('newAmenity')->schemaComponent('amenities'));
    }
}
