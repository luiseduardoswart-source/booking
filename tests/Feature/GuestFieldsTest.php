<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The maximum number of guests sits with the rules of an accommodation, and
 * "guests included in the price" says honestly what an empty field does.
 */
class GuestFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]));
    }

    /** The label of the tab a field sits in. */
    private static function tabOf(TextInput $field): ?string
    {
        $component = $field->getContainer()->getParentComponent();

        while ($component !== null && ! $component instanceof Tab) {
            $component = $component->getContainer()->getParentComponent();
        }

        return $component?->getLabel();
    }

    public function test_max_guests_is_under_the_rules_of_the_accommodation(): void
    {
        Livewire::test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertFormFieldExists('max_guests', fn (TextInput $field): bool => self::tabOf($field) === __('Rules'))
            ->fillForm(['max_guests' => 6])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(6, $this->accommodation->fresh()->max_guests);
    }

    public function test_max_guests_stays_required(): void
    {
        Livewire::test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->fillForm(['max_guests' => null])
            ->call('save')
            ->assertHasFormErrors(['max_guests' => 'required']);
    }

    public function test_the_defaults_have_no_max_guests_and_still_save(): void
    {
        Livewire::test(EditDefaults::class)
            ->assertFormFieldHidden('max_guests')
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_empty_included_guests_follows_the_default_on_an_accommodation(): void
    {
        Property::master()->update(['included_guests' => 2]);

        Livewire::test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertFormFieldExists('prices.included_guests', fn (TextInput $field): bool => $field->getHint() === __('Empty = follow the default')
                && (string) $field->getPlaceholder() === '2');

        Livewire::test(EditDefaults::class)
            ->assertFormFieldExists('prices.included_guests', fn (TextInput $field): bool => $field->getHint() === __('Empty = no extra guest fee')
                && $field->getPlaceholder() === null);
    }

    public function test_without_a_default_empty_included_guests_means_no_fee(): void
    {
        Livewire::test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertFormFieldExists('prices.included_guests', fn (TextInput $field): bool => $field->getHint() === __('Empty = no extra guest fee'));
    }
}
