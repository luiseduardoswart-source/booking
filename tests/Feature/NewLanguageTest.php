<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Properties\Pages\EditDefaults;
use App\Models\Property;
use App\Models\User;
use App\Services\MachineTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NewLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_languages_appear_automatic_in_the_language_switcher(): void
    {
        Property::create([
            'name' => 'Huis A', 'slug' => 'house-a', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
        Property::create([
            'name' => 'Huis B', 'slug' => 'house-b', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('/language/es', false)
            ->assertSee('/language/zh', false);

        // Switching to the new languages works immediately.
        $this->get('/language/es')->assertRedirect();
        $this->get('/language/zh')->assertRedirect();
    }

    public function test_new_languages_get_automatic_fields_in_the_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->assertSeeHtml('data.company_slogan_es')
            ->assertSeeHtml('data.company_slogan_zh');

        Livewire::actingAs($admin)
            ->test(EditDefaults::class)
            ->assertSeeHtml('data.mail_texts.confirmation.es')
            ->assertSeeHtml('data.mail_texts.review.zh');
    }

    public function test_translate_button_takes_new_languages_automatic_along(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });

        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(Settings::class)
            ->fillForm(['company_slogan_en' => 'Sleep by the sea'])
            ->callAction('translate')
            ->assertFormSet([
                'company_slogan_es' => '[es] Sleep by the sea',
                'company_slogan_zh' => '[zh] Sleep by the sea',
            ]);
    }

    public function test_long_translate_command_generates_a_complete_language_file(): void
    {
        $this->app->instance(MachineTranslator::class, new class implements MachineTranslator
        {
            public function translate(string $text, string $from, string $to): string
            {
                return "[{$to}] {$text}";
            }
        });

        $path = lang_path('xx.json');

        try {
            $this->artisan('lang:translate', ['locale' => 'xx'])->assertSuccessful();

            $this->assertFileExists($path);
            $translations = json_decode(file_get_contents($path), true);

            // All keys from the canonical set are translated.
            $source = json_decode(file_get_contents(lang_path('en.json')), true);
            $this->assertSame(count($source), count($translations));

            // Placeholders have stayed protected.
            $this->assertSame('[xx] '.$source['Confirmation of your booking :number'], $translations['Confirmation of your booking :number']);

            // Plural forms keep their segment structure.
            $this->assertStringContainsString('|', $translations['{1} 1 review|[2,*] :count reviews']);
        } finally {
            @unlink($path);
        }
    }
}
