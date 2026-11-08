<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\StyleEditor;
use App\Models\Property;
use App\Models\User;
use App\Support\VisualStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StyleEditorTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        return Property::create([
            'name' => 'Stijlhuis', 'slug' => 'stijlhuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
    }

    public function test_cleaning_rejects_unsafe_selectors_and_values(): void
    {
        $clean = VisualStyle::clean([
            ['selector' => 'body > :nth-child(2)', 'declarations' => ['color' => '#ff0000']],
            ['selector' => '</style><script>', 'declarations' => ['color' => 'red']],
            ['selector' => '@import "x"', 'declarations' => ['color' => 'red']],
            ['selector' => '.okay', 'declarations' => [
                'color' => 'red; } body { display:none',
                'background-image' => 'url(http://malicious)',
                'behavior' => 'url(x)',
                'background-color' => 'expression(alert(1))',
                'font-size' => '18px',
            ]],
        ]);

        $this->assertCount(2, $clean);
        $this->assertSame('body > :nth-child(2)', $clean[0]['selector']);
        // Of .okay only font-size survives.
        $this->assertSame(['font-size' => '18px'], $clean[1]['declarations']);
    }

    public function test_css_is_with_important_in_the_site_set(): void
    {
        $property = $this->property();

        VisualStyle::save([
            ['selector' => 'h1', 'declarations' => ['color' => '#123456', 'font-size' => '40px']],
        ]);

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('data-visual-style', false)
            ->assertSee('h1{color:#123456 !important;font-size:40px !important;}', false);
    }

    public function test_picker_is_only_for_admin_with_parameter_injected(): void
    {
        $property = $this->property();
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        // Guest with parameter: no picker. (First, because actingAs stays
        // in effect for all later requests in the same test.)
        $this->get("/{$property->slug}?style-editor=1")
            ->assertOk()->assertDontSee('style-editor.js', false);

        // Admin without parameter: no picker.
        $this->actingAs($admin)->get("/{$property->slug}")
            ->assertOk()->assertDontSee('style-editor.js', false);

        // Admin with parameter: picker present.
        $this->actingAs($admin)->get("/{$property->slug}?style-editor=1")
            ->assertOk()->assertSee('style-editor.js', false);
    }

    public function test_save_via_the_admin_page_cleans_and_saves(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(StyleEditor::class)
            ->call('save', [
                ['selector' => '.button-accent', 'declarations' => ['background-color' => '#111111']],
                ['selector' => '<script>', 'declarations' => ['color' => 'red']],
            ]);

        $rules = VisualStyle::rules();
        $this->assertCount(1, $rules);
        $this->assertSame('.button-accent', $rules[0]['selector']);
    }

    public function test_menu_of_pages_is_on_the_picker_passed_along(): void
    {
        $property = $this->property();
        \App\Models\Page::create(['title' => 'Over ons', 'in_menu' => true]);
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)->get("/{$property->slug}?style-editor=1")
            ->assertOk()
            ->assertSee('window.__swMenu', false)
            ->assertSee('/over-ons', false);
    }

    public function test_quick_page_via_the_editor(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $outcome = Livewire::actingAs($admin)
            ->test(StyleEditor::class)
            ->call('quickPage', 'Veelgestelde vragen');

        $this->assertDatabaseHas('pages', [
            'slug' => 'veelgestelde-vragen',
            'in_menu' => true,
        ]);
    }

    public function test_editor_is_not_in_the_menu_but_is_does_reachable(): void
    {
        $this->assertFalse(StyleEditor::shouldRegisterNavigation());

        $admin = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($admin)->get(StyleEditor::getUrl())->assertOk();
    }

    public function test_empty_rule_set_cleans_up_the_setting_on(): void
    {
        VisualStyle::save([['selector' => 'h1', 'declarations' => ['color' => 'red']]]);
        VisualStyle::save([]);

        $this->assertSame([], VisualStyle::rules());

        $property = $this->property();
        $this->get("/{$property->slug}")->assertOk()->assertDontSee('data-visual-style', false);
    }
}
