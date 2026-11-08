<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The short description is not visible on the site; an info icon next to
 * the field tells where it does appear.
 */
class ShortDescriptionExplanationTest extends TestCase
{
    use RefreshDatabase;

    private function field($page, string $path)
    {
        return collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field) => $field->getStatePath() === $path);
    }

    public function test_the_field_has_a_info_icon_with_explanation_in_the_language_of_the_admin(): void
    {
        $administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        app()->setLocale('en');

        foreach ([
            $this->field(Livewire::actingAs($administrator)->test(EditProperty::class, ['record' => $accommodation->getKey()]), 'data.short_description'),
            $this->field(Livewire::actingAs($administrator)->test(CreateProperty::class), 'data.short_description'),
        ] as $field) {
            $this->assertNotNull($field);
            $this->assertSame(Heroicon::OutlinedInformationCircle, $field->getHintIcon());
            $this->assertStringContainsString('Google', $field->getHintIconTooltip());
            $this->assertStringContainsString('WhatsApp', $field->getHintIconTooltip());
            $this->assertStringStartsWith('Not shown as text on the accommodation page.', $field->getHintIconTooltip());
        }
    }
}
