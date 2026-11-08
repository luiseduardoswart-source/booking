<?php

namespace Tests\Feature;

use App\Enums\CancellationPolicy;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Property;
use App\Models\User;
use App\Services\MachineTranslator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rental terms, house rules and the own cancellation text per language, with
 * inheritance of the defaults: first the record, then the language.
 */
class MultilingualRulesTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(array $extra = []): Property
    {
        return Property::create(array_merge([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ], $extra));
    }

    private function default(array $fields): void
    {
        Property::master()->update($fields);
    }

    public function test_the_guest_sees_the_translation_of_the_default(): void
    {
        $this->default([
            'rental_terms' => 'Niet roken.',
            'rental_terms_translations' => ['en' => 'No smoking.'],
        ]);
        $accommodation = $this->accommodation();

        app()->setLocale('en');
        $this->assertSame('No smoking.', $accommodation->effectiveLocalized('rental_terms'));

        app()->setLocale('de');
        $this->assertSame('Niet roken.', $accommodation->effectiveLocalized('rental_terms'));
    }

    public function test_own_text_takes_precedence_even_if_the_default_has_a_translation(): void
    {
        $this->default([
            'house_rules' => 'Standaard huisregels.',
            'house_rules_translations' => ['en' => 'Default house rules.'],
        ]);
        $accommodation = $this->accommodation(['house_rules' => 'Eigen huisregels.']);

        // An English guest gets the own (Dutch) text of this property,
        // not the English text of the default: that is different content.
        app()->setLocale('en');
        $this->assertSame('Eigen huisregels.', $accommodation->effectiveLocalized('house_rules'));
    }

    public function test_own_translation_of_the_accommodation_is_used(): void
    {
        $accommodation = $this->accommodation([
            'house_rules' => 'Eigen huisregels.',
            'house_rules_translations' => ['en' => 'Own house rules.'],
        ]);

        app()->setLocale('en');
        $this->assertSame('Own house rules.', $accommodation->effectiveLocalized('house_rules'));
    }

    public function test_the_terms_at_booking_are_in_the_language_of_the_guest(): void
    {
        $this->default([
            'rental_terms' => 'Niet roken.',
            'rental_terms_translations' => ['en' => 'No smoking.'],
        ]);

        app()->setLocale('en');
        $this->assertStringContainsString('No smoking.', $this->accommodation()->bookingTermsText(3));
    }

    public function test_own_cancellation_text_is_translatable(): void
    {
        $accommodation = $this->accommodation([
            'cancellation_policy' => CancellationPolicy::Custom,
            'cancellation_policy_text' => 'Geen restitutie.',
            'cancellation_policy_text_translations' => ['en' => 'No refund.'],
        ]);

        app()->setLocale('en');
        $this->assertContains('No refund.', $accommodation->cancellationPolicyLines());
    }

    public function test_translations_of_the_defaults_are_saved(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->fillForm([
                'rental_terms' => 'No smoking.',
                'rental_terms_translations' => ['nl' => 'Niet roken.'],
                'house_rules_translations' => ['de' => 'Ruhe ab 22 Uhr.'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $master = Property::master()->fresh();
        $this->assertSame('Niet roken.', $master->rental_terms_translations['nl']);
        $this->assertSame('Ruhe ab 22 Uhr.', $master->house_rules_translations['de']);
    }

    public function test_translate_button_fills_the_form_but_does_not_save(): void
    {
        $this->app->bind(MachineTranslator::class, fn () => new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "{$text} [{$to}]";
            }
        });

        $accommodation = $this->accommodation(['rental_terms' => 'Niet roken.']);
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->callAction(TestAction::make('translate_rental_terms')->schemaComponent('rental_terms_section.translations_rental_terms', schema: 'form'))
            ->assertSchemaStateSet(['rental_terms_translations.nl' => 'Niet roken. [nl]'], 'form');

        // Not saved yet: review first, then save.
        $this->assertNull($accommodation->fresh()->rental_terms_translations);
    }
}
