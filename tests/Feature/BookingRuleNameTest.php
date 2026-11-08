<?php

namespace Tests\Feature;

use App\Engines\AvailabilityEngine;
use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Property;
use App\Models\User;
use App\Services\MachineTranslator;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The name of a booking rule prefixes the notification to the guest, in their
 * language: "High season: Minimum stay is 7 nights."
 */
class BookingRuleNameTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    /** @return array<int, string> */
    private function reasons(Property $accommodation): array
    {
        return app(AvailabilityEngine::class)->check(
            $accommodation,
            CarbonImmutable::parse('2026-12-01'),
            CarbonImmutable::parse('2026-12-04'), // 3 nights
        )->reasons;
    }

    public function test_the_name_is_in_the_language_of_the_guest_for_the_notification(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->bookingRules()->create([
            'name' => 'Hoogseizoen',
            'name_translations' => ['en' => 'High season'],
            'min_nights' => 7,
        ]);

        app()->setLocale('en');
        $this->assertSame(['High season: Minimum stay is 7 nights.'], $this->reasons($accommodation));

        app()->setLocale('nl');
        $this->assertSame(['Hoogseizoen: Minimaal verblijf is 7 nachten.'], $this->reasons($accommodation));
    }

    public function test_without_translation_falls_the_name_back_on_the_source_text(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->bookingRules()->create(['name' => 'Hoogseizoen', 'min_nights' => 7]);

        app()->setLocale('en');
        $this->assertSame(['Hoogseizoen: Minimum stay is 7 nights.'], $this->reasons($accommodation));
    }

    public function test_a_rule_without_name_gives_the_regular_notification(): void
    {
        $accommodation = $this->accommodation();
        $accommodation->bookingRules()->create(['min_nights' => 7]);

        app()->setLocale('en');
        $this->assertSame(['Minimum stay is 7 nights.'], $this->reasons($accommodation));
    }

    public function test_the_name_of_a_default_rule_is_also_translated(): void
    {
        Property::master()->bookingRules()->create([
            'name' => 'Hoogseizoen',
            'name_translations' => ['de' => 'Hochsaison'],
            'min_nights' => 7,
        ]);

        app()->setLocale('de');
        $this->assertStringStartsWith('Hochsaison: ', $this->reasons($this->accommodation())[0]);
    }

    public function test_name_and_translation_are_via_the_form_saved(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $restore = Repeater::fake();

        Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->fillForm(['bookingRules' => [[
                'name' => 'Hoogseizoen',
                'name_translations' => ['nl' => 'Hoogseizoen vertaald'],
                'min_nights' => 7,
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $restore();

        $rule = Property::master()->bookingRules()->firstOrFail();
        $this->assertSame('Hoogseizoen', $rule->name);
        $this->assertSame('Hoogseizoen vertaald', $rule->name_translations['nl']);
    }

    public function test_the_translate_button_works_also_inside_a_rule(): void
    {
        $this->app->bind(MachineTranslator::class, fn () => new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "{$text} [{$to}]";
            }
        });

        $rule = Property::master()->bookingRules()->create(['name' => 'Hoogseizoen', 'min_nights' => 7]);
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        // A repeater tied to the database keys existing rules
        // as "record-{id}".
        $item = "bookingRules.record-{$rule->id}";

        Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->callAction(TestAction::make('translate_name')->schemaComponent("{$item}.translations_name", schema: 'form'))
            ->assertSchemaStateSet(["{$item}.name_translations.nl" => 'Hoogseizoen [nl]'], 'form');

        // Only the form is filled, not saved yet.
        $this->assertNull($rule->fresh()->name_translations);
    }
}
