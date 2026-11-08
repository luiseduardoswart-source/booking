<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Amenities\Pages\ManageAmenities;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;
use App\Support\AmenityIcons;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every amenity has its own icon, from a single set (resources/icons/amenities.php).
 */
class AmenityIconsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodation(array $extra = []): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL', ...$extra,
        ]);
    }

    public function test_every_default_amenity_has_a_own_icon(): void
    {
        foreach (Amenity::DEFAULT as $name => $icon) {
            $this->assertNotNull(AmenityIcons::inside($icon), "{$name}: {$icon}");
        }

        $this->assertCount(count(Amenity::DEFAULT), array_unique(Amenity::DEFAULT));
    }

    public function test_every_icon_is_to_choose_with_a_name(): void
    {
        app()->setLocale('nl');
        $options = Amenity::iconOptions();

        $this->assertEqualsCanonicalizing(array_keys(AmenityIcons::all()), array_keys($options));
        $this->assertSame('Haardroger', $options['hair-dryer']);
        $this->assertSame('Vinkje (standaard)', $options['check']);

        foreach ($options as $icon => $label) {
            $this->assertNotSame($icon, $label, "{$icon} has no name");
        }
    }

    public function test_icons_contain_only_drawing_elements(): void
    {
        // They are put into the page unfiltered, so no scripts, links or events.
        foreach (AmenityIcons::all() as $icon => $svg) {
            $this->assertMatchesRegularExpression(
                '~^(<(path|circle|rect|line|polyline|polygon|ellipse)( (d|cx|cy|r|rx|ry|x|y|width|height|x1|y1|x2|y2|points|fill)="[^"<>]*")*/>)+$~',
                $svg,
                $icon,
            );
        }
    }

    public function test_the_site_shows_the_own_icon(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->amenities()->attach(Amenity::firstWhere('name', 'Hair dryer'));

        $this->get('/strandhuis')
            ->assertOk()
            ->assertSee(AmenityIcons::inside('hair-dryer'), false);
    }

    public function test_old_icons_are_converted_and_own_choices_stay(): void
    {
        // The state from before this change.
        $bad = Amenity::firstWhere('name', 'Bathtub');
        $bad->update(['icon' => 'pool']);                // the old default icon
        $sauna = Amenity::firstWhere('name', 'Sauna');
        $sauna->update(['icon' => 'pool']);              // chosen by the owner (default was 'fire')
        $boat = Amenity::create(['name' => 'Sailing boat', 'icon' => 'kitchen']);
        $custom = Amenity::create(['name' => 'Sea view', 'icon' => 'wifi']);
        $accommodation = $this->accommodation(['highlights' => [['icon' => 'pool', 'title' => 'Zwembad'], ['icon' => 'clock', 'title' => 'Inchecken']]]);

        $migration = require database_path('migrations/2026_11_03_160000_own_icon_per_amenity.php');
        $migration->up();

        $this->assertNull($bad->fresh()->icon);
        $this->assertSame('bath', $bad->fresh()->iconName());
        $this->assertSame('waves-ladder', $sauna->fresh()->icon);
        $this->assertSame('chef-hat', $boat->fresh()->icon);
        $this->assertSame('wifi', $custom->fresh()->icon);
        $this->assertSame(['waves-ladder', 'clock'], array_column($accommodation->fresh()->highlights, 'icon'));
        $this->assertSame('Zwembad', $accommodation->fresh()->highlights[0]['title']);

        $migration->down();

        $this->assertSame('pool', $bad->fresh()->icon);
        $this->assertSame('pool', $sauna->fresh()->icon);
        $this->assertSame('kitchen', $boat->fresh()->icon);
        $this->assertSame(['pool', 'clock'], array_column(json_decode(DB::table('properties')->where('id', $accommodation->id)->value('highlights'), true), 'icon'));
    }

    public function test_the_admin_chooses_only_icons_off_the_set(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $boat = Amenity::create(['name' => 'Sailing boat']);

        Livewire::actingAs($admin)->test(ManageAmenities::class)
            ->callAction(TestAction::make('edit')->table($boat), data: ['icon' => 'kayak', 'category' => Amenity::OTHER])
            ->assertHasNoActionErrors();
        $this->assertSame('kayak', $boat->fresh()->icon);

        Livewire::actingAs($admin)->test(ManageAmenities::class)
            ->callAction(TestAction::make('edit')->table($boat), data: ['icon' => '<script>', 'category' => Amenity::OTHER])
            ->assertHasActionErrors(['icon']);
        $this->assertSame('kayak', $boat->fresh()->icon);
    }
}
