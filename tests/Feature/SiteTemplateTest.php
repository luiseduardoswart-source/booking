<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings;
use App\Models\Property;
use App\Models\Setting;
use App\Support\SiteTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SiteTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function makePage(): Property
    {
        return Property::create([
            'name' => 'Templatehuis', 'slug' => 'templatehuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
    }

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);
    }

    public function test_all_bundled_templates_have_valid_tokens(): void
    {
        $templates = SiteTemplates::all();

        $this->assertCount(4, $templates);

        foreach ($templates as $key => $template) {
            $this->assertNotEmpty($template['name'], $key);
            // A preset must not contain anything the cleaning would throw away.
            $this->assertSame(
                SiteTemplates::cleanTokens($template['tokens']),
                $template['tokens'],
                "Preset '{$key}' contains a value that does not survive validation",
            );
        }
    }

    public function test_cleaning_rejects_css_injection_and_unknown_values(): void
    {
        $clean = SiteTemplates::cleanTokens([
            'accent_color' => '</style><script>alert(1)</script>',
            'background_color' => 'red; } body { display: none',
            'header_style' => 'purple',
            'font_style' => 'serif',
            'unknown_field' => 'x',
        ]);

        $this->assertNull($clean['accent_color']);
        $this->assertNull($clean['background_color']);
        $this->assertNull($clean['header_style']);
        $this->assertSame('serif', $clean['font_style']);
        $this->assertArrayNotHasKey('unknown_field', $clean);
    }

    public function test_style_settings_are_injected_into_the_site(): void
    {
        $property = $this->makePage();

        foreach (SiteTemplates::all()['luxury']['tokens'] as $field => $value) {
            Setting::set('frontend', $field, $value);
        }

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('--color-accent: #a16207', false)
            ->assertSee('background-color: #faf9f7', false)
            ->assertSee('.site-header', false)
            ->assertSee("'Lora', Georgia", false)
            ->assertSee('border-radius: 0.5rem', false)
            ->assertSee('.text-rose-600 { color: var(--color-accent)', false);
    }

    public function test_without_settings_there_is_no_style_block(): void
    {
        $property = $this->makePage();

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertDontSee('--color-accent', false)
            ->assertDontSee('.site-header {', false);
    }

    public function test_choosing_a_template_fills_in_the_style_fields(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Settings::class)
            ->fillForm(['frontend_template_name' => 'beach'])
            ->assertSchemaStateSet([
                'frontend_accent_color' => '#0e7490',
                'frontend_background_color' => '#fbfaf6',
                'frontend_header_style' => 'light',
                'frontend_button_style' => 'round',
            ]);
    }

    public function test_export_delivers_a_template_file(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Settings::class)
            ->fillForm(['frontend_template_name' => 'minimum'])
            ->call('exportTemplate')
            ->assertFileDownloaded('website-template.json');
    }

    public function test_import_loads_a_valid_file_and_rejects_junk(): void
    {
        $component = Livewire::actingAs($this->admin())->test(Settings::class);

        $component->call('importTemplate', json_encode([
            'template' => 'luxury',
            'tokens' => ['accent_color' => '#123456', 'font_style' => 'serif'],
        ]));

        $this->assertSame('#123456', $component->get('data.frontend_accent_color'));
        $this->assertSame('serif', $component->get('data.frontend_font_style'));
        $this->assertSame('luxury', $component->get('data.frontend_template_name'));

        // Junk: proper error message, style stays as it is.
        $component->call('importTemplate', 'dit is geen json');
        $this->assertSame('#123456', $component->get('data.frontend_accent_color'));
    }
}
