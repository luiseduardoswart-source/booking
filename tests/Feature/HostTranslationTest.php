<?php

namespace Tests\Feature;

use App\Models\Host;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bio_falls_back_to_the_dutch(): void
    {
        $host = Host::create(['name' => 'Marieke', 'bio' => 'Ik verhuur al tien jaar mijn huisje aan zee.']);

        app()->setLocale('fr');
        $this->assertSame('Ik verhuur al tien jaar mijn huisje aan zee.', $host->localizedBio());
    }

    public function test_own_translation_is_used(): void
    {
        $host = Host::create([
            'name' => 'Marieke',
            'bio' => 'Ik verhuur al tien jaar mijn huisje aan zee.',
            'bio_translations' => ['en' => 'I have been renting out my seaside cottage for ten years.'],
        ]);

        app()->setLocale('en');
        $this->assertSame('I have been renting out my seaside cottage for ten years.', $host->localizedBio());

        app()->setLocale('de');
        $this->assertSame('Ik verhuur al tien jaar mijn huisje aan zee.', $host->localizedBio());
    }

    public function test_translated_bio_appears_on_the_site(): void
    {
        $host = Host::create([
            'name' => 'Marieke',
            'bio' => 'Ik verhuur al tien jaar mijn huisje aan zee.',
            'bio_translations' => ['en' => 'I rent out my seaside cottage.'],
        ]);

        $property = Property::create([
            'name' => 'Hosthuis', 'slug' => 'hosthuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
        $property->hosts()->attach($host);

        $this->withSession(['locale' => 'en'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('I rent out my seaside cottage.')
            ->assertDontSee('Ik verhuur al tien jaar');
    }

    public function test_translate_button_keeps_filled_in_languages_and_fills_empty_bios(): void
    {
        $offered = new \ArrayObject;
        $this->app->bind(\App\Services\MachineTranslator::class, fn () => new class($offered) implements \App\Services\MachineTranslator
        {
            public function __construct(private \ArrayObject $offered) {}

            public function translate(string $text, string $from, string $to): string
            {
                $this->offered[] = $to;

                return "{$text} [{$to}]";
            }
        });

        $withBio = Host::create(['name' => 'Marieke', 'bio' => 'Hello!', 'bio_translations' => ['nl' => 'Hallo!']]);
        $withoutBio = Host::create(['name' => 'Piet']);

        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);

        \Livewire\Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Hosts\Pages\ManageHosts::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('translate')->table());

        // 'nl' already had a translation, so only the other four languages were offered.
        $this->assertSame(['de', 'fr', 'es', 'zh'], $offered->getArrayCopy());

        $translations = $withBio->fresh()->bio_translations;
        $this->assertSame('Hallo!', $translations['nl']);
        $this->assertSame('Hello! [de]', $translations['de']);

        // The host without a bio is left untouched.
        $this->assertNull($withoutBio->fresh()->bio_translations);
    }
}
