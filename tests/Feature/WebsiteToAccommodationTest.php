<?php

namespace Tests\Feature;

use App\Enums\CancellationPolicy;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Language;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Services\MachineTranslator;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ID verification, price in the calendar, social links and the texts of the fixed
 * cancellation policies used to be under Settings → Website. They now belong to the
 * default accommodation settings and can be overridden per accommodation.
 */
class WebsiteToAccommodationTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(array $fields = []): Property
    {
        return Property::create([
            'name' => 'Huis', 'slug' => 'house-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, 'base_price_cents' => 10000, ...$fields,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_settings_show_this_parts_not_more(): void
    {
        Livewire::actingAs($this->owner())
            ->test(Settings::class)
            ->assertDontSee(__('Show the price per night in the calendar'))
            ->assertDontSee(__('Text of the fixed policies'))
            ->assertDontSeeHtml('data.social_facebook')
            ->assertDontSeeHtml('data.cancellation_text_flexible_nl')
            ->assertDontSeeHtml('data.verification_id_required');
    }

    public function test_the_defaults_save_all_four_the_parts(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm([
                'id_required' => '1',
                'prices_in_calendar' => '1',
                'social_links.instagram' => 'https://instagram.com/company',
                'cancellation_texts.moderate.nl' => 'Onze eigen regel',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $master = Property::master();

        $this->assertTrue((bool) $master->id_required);
        $this->assertTrue((bool) $master->prices_in_calendar);
        $this->assertSame('https://instagram.com/company', $master->social_links['instagram']);
        $this->assertSame('Onze eigen regel', $master->cancellation_texts['moderate']['nl']);
    }

    public function test_a_choice_on_the_defaults_may_not_empty_are(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm(['id_required' => null, 'prices_in_calendar' => null])
            ->call('save')
            ->assertHasFormErrors(['id_required', 'prices_in_calendar']);
    }

    public function test_a_unsafe_social_link_is_not_accepted(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->fillForm(['social_links.facebook' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasFormErrors(['social_links.facebook']);
    }

    public function test_the_defaults_show_the_built_in_cancellation_text_to_on_to_adjust(): void
    {
        app()->setLocale('nl');

        Livewire::actingAs($this->owner())
            ->test(EditDefaults::class)
            ->assertFormSet([
                'cancellation_texts.flexible.nl' => implode("\n", CancellationPolicy::Flexible->defaultLines()),
            ]);
    }

    public function test_the_built_in_text_is_also_in_the_other_languages_ready(): void
    {
        $english = __('Full refund up to 24 hours before check-in.', [], 'en');

        $screen = Livewire::actingAs($this->owner())->test(EditDefaults::class);

        $this->assertStringContainsString($english, $screen->get('data.cancellation_texts.flexible.en'));
    }

    public function test_a_accommodation_gets_the_text_of_the_default_not_as_own_text(): void
    {
        // Otherwise every save would detach the default from the accommodation.
        Property::master()->update(['cancellation_texts' => ['strict' => ['nl' => 'Van de standaard']]]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertSeeHtml('placeholder="Van de standaard"')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], array_filter($accommodation->refresh()->cancellation_texts['strict'] ?? []));
        $this->assertSame(['Van de standaard'], $accommodation->cancellationRules(CancellationPolicy::Strict));
    }

    public function test_a_accommodation_can_the_text_override(): void
    {
        app()->setLocale('nl');
        Property::master()->update(['cancellation_texts' => ['strict' => ['nl' => 'Van de standaard']]]);
        $accommodation = $this->accommodation();

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['cancellation_texts.strict.nl' => 'Van dit huis'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Van dit huis'], $accommodation->refresh()->cancellationRules(CancellationPolicy::Strict));
    }

    public function test_the_example_rules_show_the_text_of_the_accommodation(): void
    {
        app()->setLocale('nl');
        $accommodation = $this->accommodation([
            'cancellation_policy' => CancellationPolicy::Strict,
            'cancellation_texts' => ['strict' => ['nl' => 'Regel van dit huis']],
        ]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->assertSee('Regel van dit huis');
    }

    public function test_the_translate_button_fills_the_cancellation_texts(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });

        $accommodation = $this->accommodation(['cancellation_texts' => ['strict' => ['nl' => 'Regel', 'en' => 'Rule']]]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->callAction(TestAction::make('translate_cancellation')->schemaComponent('fixed_policy_texts', schema: 'form'))
            ->assertFormSet([
                'cancellation_texts.strict.nl' => 'Regel',
                'cancellation_texts.strict.de' => '[de] Rule',
            ]);
    }

    public function test_a_cancellation_text_in_a_switched_off_language_stays_saved(): void
    {
        Language::query()->where('code', 'de')->update(['active' => false]);
        Language::forgets();

        $accommodation = $this->accommodation(['cancellation_texts' => ['strict' => ['nl' => 'Hoi', 'de' => 'Hallo']]]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Hallo', $accommodation->refresh()->cancellation_texts['strict']['de']);
    }

    public function test_the_footer_shows_the_links_of_the_default(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/company']]);
        $this->accommodation();
        $this->accommodation();

        $this->get('/')->assertOk()->assertSee('https://facebook.com/company', false);
    }

    /** Only the footer: the page itself may show the same links too. */
    private function footer(string $html): string
    {
        return substr($html, (int) strpos($html, '<footer'));
    }

    public function test_the_footer_of_a_accommodation_shows_her_own_links(): void
    {
        Property::master()->update(['social_links' => [
            'facebook' => 'https://facebook.com/general',
            'instagram' => 'https://instagram.com/general',
        ]]);
        $custom = $this->accommodation(['social_links' => ['instagram' => 'https://instagram.com/this-house']]);

        $footer = $this->footer($this->get("/{$custom->slug}")->assertOk()->getContent());

        $this->assertStringContainsString('https://instagram.com/this-house', $footer);
        $this->assertStringNotContainsString('https://instagram.com/general', $footer);
        // Not filled in on the accommodation: the general link.
        $this->assertStringContainsString('https://facebook.com/general', $footer);
    }

    public function test_the_footer_of_a_accommodation_without_own_links_shows_the_general(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/general']]);
        $accommodation = $this->accommodation();

        $footer = $this->footer($this->get("/{$accommodation->slug}")->assertOk()->getContent());

        $this->assertStringContainsString('https://facebook.com/general', $footer);
    }

    public function test_the_footer_of_a_other_page_shows_the_general_links(): void
    {
        Property::master()->update(['social_links' => ['instagram' => 'https://instagram.com/general']]);
        $this->accommodation(['social_links' => ['instagram' => 'https://instagram.com/this-house']]);
        $this->accommodation();

        $footer = $this->footer($this->get('/')->assertOk()->getContent());

        $this->assertStringContainsString('https://instagram.com/general', $footer);
        $this->assertStringNotContainsString('https://instagram.com/this-house', $footer);
    }

    public function test_the_footer_lets_no_unsafe_general_link_through(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'javascript:alert(1)']]);
        $this->accommodation();
        $this->accommodation();

        $this->assertStringNotContainsString('javascript:alert', $this->footer($this->get('/')->assertOk()->getContent()));
    }

    public function test_the_gray_text_shows_the_link_of_the_default(): void
    {
        Property::master()->update(['social_links' => ['facebook' => 'https://facebook.com/company']]);

        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertSeeHtml('placeholder="https://facebook.com/company"');
    }

    public function test_the_move_takes_the_old_settings_along(): void
    {
        foreach ([
            ['verification', 'id_required', '1'],
            ['site', 'prices_in_calendar', '1'],
            ['site', 'currency_picker', '1'],
            ['social', 'instagram', 'https://instagram.com/company'],
            ['social', 'x', ''],
            ['cancellation', 'text_strict_nl', 'Streng in het Nederlands'],
            ['cancellation', 'text_strict_en', 'Strict in English'],
        ] as [$group, $key, $value]) {
            Setting::set($group, $key, $value);
        }

        // No master record beforehand: the values must not be lost then.
        $this->assertFalse(DB::table('properties')->where('is_master', true)->exists());

        $migration = require database_path('migrations/2026_10_30_150000_cancellation_social_per_accommodation.php');
        (new \ReflectionMethod($migration, 'moveSettings'))->invoke($migration);

        $master = Property::master();

        $this->assertTrue((bool) $master->id_required);
        $this->assertTrue((bool) $master->prices_in_calendar);
        $this->assertSame(['instagram' => 'https://instagram.com/company'], $master->social_links);
        $this->assertSame(['nl' => 'Streng in het Nederlands', 'en' => 'Strict in English'], $master->cancellation_texts['strict']);

        $this->assertNull(Setting::get('verification', 'id_required'));
        $this->assertNull(Setting::get('social', 'instagram'));
        $this->assertNull(Setting::get('cancellation', 'text_strict_nl'));
        $this->assertSame('1', Setting::get('site', 'currency_picker'));
    }

    public function test_the_move_lets_a_existing_choice_of_the_default_are(): void
    {
        Property::master()->update(['id_required' => true, 'social_links' => ['facebook' => 'https://facebook.com/custom']]);
        Setting::set('verification', 'id_required', '0');
        Setting::set('social', 'facebook', 'https://facebook.com/old');

        $migration = require database_path('migrations/2026_10_30_150000_cancellation_social_per_accommodation.php');
        (new \ReflectionMethod($migration, 'moveSettings'))->invoke($migration);

        $master = Property::master();

        $this->assertTrue((bool) $master->id_required);
        $this->assertSame('https://facebook.com/custom', $master->social_links['facebook']);
    }
}
