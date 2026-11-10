<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Models\Property;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The editors for texts on the site (description, pages) have the full
 * toolbar, with only buttons whose result also shows on the site: no text
 * colour.
 */
class SiteEditorToolbarTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRA = ['highlight', 'lead', 'small', 'alignJustify', 'horizontalRule', 'details', 'clearFormatting'];

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    public function test_the_description_editor_has_the_extra_buttons(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $page = Livewire::actingAs($this->owner())->test(EditProperty::class, ['record' => $accommodation->getKey()]);

        foreach (self::EXTRA as $tool) {
            $page->assertSeeHtml('data-rich-editor-tool="'.$tool.'"');
        }
        // Everything that was there stays.
        foreach (['bold', 'bulletList', 'orderedList', 'table', 'attachFiles', 'undo'] as $tool) {
            $page->assertSeeHtml('data-rich-editor-tool="'.$tool.'"');
        }
        $page->assertDontSeeHtml('data-rich-editor-tool="textColor"');
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    /** The description editor in the source language. */
    private const DESCRIPTION = 'translations_name_short_description_description.name_short_description_description_en.description';

    public function test_media_library_and_source_code_buttons_and_uploads_into_the_media_library(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertSeeHtml('data-rich-editor-tool="mediaLibrary"')
            ->assertSeeHtml('data-rich-editor-tool="sourceCode"')
            ->assertSeeHtml('window.pickMedia')
            ->assertFormFieldExists(self::DESCRIPTION, 'form', fn ($editor): bool => $editor->getFileAttachmentsDiskName() === 'public'
                && $editor->getFileAttachmentsDirectory() === 'media/content');
    }

    public function test_without_media_access_there_is_no_media_library_button(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'permissions' => ['accommodations' => \App\Support\Access::ACTIONS]]);

        Livewire::actingAs($staff)
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->assertDontSeeHtml('data-rich-editor-tool="mediaLibrary"')
            ->assertSeeHtml('data-rich-editor-tool="sourceCode"');
    }

    public function test_the_source_code_window_shows_and_replaces_the_html(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation()->getKey()])
            ->mountAction(TestAction::make('sourceCode')->schemaComponent(self::DESCRIPTION, schema: 'form')->arguments(['html' => '<p>Old</p>']))
            ->assertActionDataSet(['html' => '<p>Old</p>'])
            ->setActionData(['html' => '<p>New</p><ul><li><p>One</p></li></ul>'])
            ->callMountedAction()
            ->assertDispatched('run-rich-editor-commands', fn (string $event, array $params): bool => $params['commands'][0]['name'] === 'setContent'
                && $params['commands'][0]['arguments'][0] === '<p>New</p><ul><li><p>One</p></li></ul>');
    }

    public function test_the_page_editor_has_the_extra_buttons(): void
    {
        // The page form is in the "create" popup: check the editor's toolbar itself.
        Livewire::actingAs($this->owner())
            ->test(ManagePages::class)
            ->mountAction(TestAction::make('create'))
            ->assertFormFieldExists('translations_title_content.title_content_en.content', 'mountedActionSchema0', function ($editor): bool {
                $tools = collect($editor->getToolbarButtons())->flatten()->all();

                return array_diff(self::EXTRA, $tools) === [] && ! in_array('textColor', $tools, true);
            });
    }
}
