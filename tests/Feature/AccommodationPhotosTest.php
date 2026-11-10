<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\PropertyResource;
use App\Livewire\AccommodationPhotos;
use App\Models\Host;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The Photos tab: one list in which you add, drag, label,
 * edit and delete photos. Everything is saved immediately and only for whoever
 * may edit the accommodation.
 */
class AccommodationPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Property $accommodation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->accommodation = $this->makeAccommodation('Strandhuis');
    }

    private function makeAccommodation(string $name): Property
    {
        return Property::create([
            'name' => $name, 'slug' => strtolower($name), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    private function photo(Property $accommodation, string $name = 'photo.jpg'): Media
    {
        return $accommodation->addPhoto(UploadedFile::fake()->image($name, 800, 600));
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function photos(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->owner())
            ->test(AccommodationPhotos::class, ['property' => $this->accommodation]);
    }

    /** @return array<int, string> */
    private function fileNames(): array
    {
        return $this->accommodation->fresh()->getMedia('photos')->pluck('file_name')->all();
    }

    public function test_the_old_upload_list_is_gone_and_the_photos_are_on_the_tab(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertFormFieldDoesNotExist('photos')
            ->assertSeeLivewire(AccommodationPhotos::class);
    }

    public function test_a_photo_added_drag_comes_immediately_at_the_end_in_the_list(): void
    {
        $this->photo($this->accommodation, 'first.jpg');

        $this->photos()
            ->set('upload', UploadedFile::fake()->image('Mooi Uitzicht.JPG', 800, 600))
            ->assertHasNoErrors()
            ->assertSet('upload', null);

        $this->assertSame(['first.jpg', 'mooi-uitzicht.jpg'], $this->fileNames());
    }

    public function test_only_images_may_added(): void
    {
        $this->photos()
            ->set('upload', UploadedFile::fake()->create('virus.php', 10, 'text/plain'))
            ->assertHasErrors('upload');

        $this->assertSame([], $this->fileNames());
    }

    public function test_a_photo_above_the_limit_is_rejected(): void
    {
        $this->photos()
            ->set('upload', UploadedFile::fake()->image('large.jpg')->size(Property::MAX_PHOTO_KB + 1))
            ->assertHasErrors('upload');

        $this->assertSame([], $this->fileNames());
    }

    public function test_more_then_the_maximum_count_photos_can_not(): void
    {
        // Copy rows instead of uploading: making real photos takes too long each.
        $first = $this->photo($this->accommodation);

        foreach (range(2, Property::MAX_PHOTOS) as $number) {
            $first->replicate()->forceFill(['uuid' => (string) Str::uuid(), 'order_column' => $number])->saveQuietly();
        }

        $this->photos()
            ->set('upload', UploadedFile::fake()->image('to-many.jpg', 800, 600))
            ->assertHasErrors('upload');

        $this->assertCount(Property::MAX_PHOTOS, $this->fileNames());
    }

    public function test_drag_determines_the_order_and_so_the_main_photo(): void
    {
        $this->photo($this->accommodation, 'a.jpg');
        $this->photo($this->accommodation, 'b.jpg');
        $c = $this->photo($this->accommodation, 'c.jpg');

        $this->photos()->call('move', $c->id, 0);

        $this->assertSame(['c.jpg', 'a.jpg', 'b.jpg'], $this->fileNames());
        $this->assertSame('c.jpg', $this->accommodation->fresh()->getFirstMedia('photos')->file_name);
    }

    public function test_a_photo_to_back_drag(): void
    {
        $a = $this->photo($this->accommodation, 'a.jpg');
        $this->photo($this->accommodation, 'b.jpg');
        $this->photo($this->accommodation, 'c.jpg');

        $this->photos()->call('move', $a->id, 2);

        $this->assertSame(['b.jpg', 'c.jpg', 'a.jpg'], $this->fileNames());
    }

    public function test_delete_fetches_the_photo_off_the_list_and_of_the_disk(): void
    {
        $stays = $this->photo($this->accommodation, 'stays.jpg');
        $gone = $this->photo($this->accommodation, 'gone.jpg');
        $path = $gone->getPathRelativeToRoot();

        $this->photos()->call('delete', $gone->id);

        $this->assertSame(['stays.jpg'], $this->fileNames());
        $this->assertNull(Media::find($gone->id));
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($stays->getPathRelativeToRoot());
    }

    public function test_a_photo_of_a_other_accommodation_stays_outside_scope(): void
    {
        $foreign = $this->photo($this->makeAccommodation('Stadshuis'));
        $custom = $this->photo($this->accommodation);

        $this->photos()
            ->call('delete', $foreign->id)
            ->call('saveLabel', $foreign->id, 'Gekaapt')
            ->call('move', $foreign->id, 0);

        $this->assertNotNull(Media::find($foreign->id));
        $this->assertNull($foreign->fresh()->getCustomProperty('label'));
        $this->assertEquals([$custom->id], $this->accommodation->fresh()->getMedia('photos')->modelKeys());
    }

    public function test_a_host_of_a_other_accommodation_can_nothing(): void
    {
        $custom = $this->photo($this->accommodation);
        $foreign = Host::create(['name' => 'Vreemd']);
        $foreign->properties()->attach($this->makeAccommodation('Stadshuis'));
        $user = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $foreign->id, 'permissions' => ['accommodations' => Access::ACTIONS],
        ]);
        $part = $this->photos()->instance();

        $this->actingAs($user);

        $this->assertFalse(PropertyResource::canEdit($this->accommodation));
        $this->expectException(HttpException::class);
        $part->delete($custom->id);
    }

    public function test_the_edit_button_is_there_only_with_the_permission_media_admin(): void
    {
        $this->photo($this->accommodation);
        $host = Host::create(['name' => 'Sarit']);
        $host->properties()->attach($this->accommodation);
        $withMedia = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id,
            'permissions' => ['accommodations' => Access::ACTIONS, 'media' => [Access::EDIT]],
        ]);
        $withoutMedia = User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id, 'permissions' => ['accommodations' => Access::ACTIONS],
        ]);

        $this->photos($withMedia)->assertSeeHtml('ImageEditor.open');
        $this->photos($withoutMedia)->assertDontSeeHtml('ImageEditor.open');
    }

    public function test_the_editor_loads_only_on_the_page_for_who_media_admin_may(): void
    {
        $url = PropertyResource::getUrl('edit', ['record' => $this->accommodation]);

        $this->actingAs($this->owner())->get($url)->assertOk()->assertSee('image-editor-modal', false);

        $host = Host::create(['name' => 'Sarit']);
        $host->properties()->attach($this->accommodation);

        $this->actingAs(User::factory()->create([
            'role' => UserRole::Host, 'host_id' => $host->id, 'permissions' => ['accommodations' => Access::ACTIONS],
        ]))->get($url)->assertOk()->assertDontSee('image-editor-modal', false);
    }

    public function test_the_save_button_disappears_on_the_photos_tab(): void
    {
        Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()])
            ->assertSeeHtml('data-tab-key="photos"')
            ->assertSeeHtml("['calendar', 'photos'].includes(\$store.accommodationTab?.active)");
    }

    /**
     * A Livewire update request has no ?tab= in its URL. When the starting tab
     * came from the server, the attribute changed on every update and the
     * morph put the save bar back on the Photos tab.
     */
    public function test_the_save_bar_state_does_not_depend_on_the_request(): void
    {
        // The tab state (x-init) and the sticky bar (x-data) of the save bar element.
        $attribute = function (Testable $page, string $name): string {
            preg_match('/<div[^>]*id="content\.form-actions"[^>]*>/', $page->html(), $tag);
            preg_match('/'.preg_quote($name, '/').'="([^"]*)"/', $tag[0] ?? '', $match);

            return html_entity_decode($match[1] ?? '', ENT_QUOTES);
        };
        $xInit = fn (Testable $page) => $attribute($page, 'x-init');

        $onPhotos = Livewire::withQueryParams(['tab' => 'photos'])->actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()]);
        $withoutTab = Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $this->accommodation->getRouteKey()]);

        $this->assertNotSame('', $xInit($onPhotos));
        $this->assertSame($xInit($onPhotos), $xInit($withoutTab));
        $this->assertSame($xInit($onPhotos), $xInit($onPhotos->call('$refresh')));
        $this->assertStringContainsString('location.search', $xInit($onPhotos));

        // Filament's own x-data stays: it keeps the bar at the bottom of the screen.
        $this->assertSame('filamentActionsSchemaComponent()', $attribute($onPhotos, 'x-data'));
    }

    public function test_the_description_saves_inline_and_enter_sends_the_form_not(): void
    {
        $this->photo($this->accommodation);

        $this->photos()
            ->assertSeeHtml('x-on:keydown.enter.prevent')
            ->assertSeeHtml('saveLabel(');
    }

    public function test_the_maximum_is_hundred_photos(): void
    {
        $this->assertSame(100, Property::MAX_PHOTOS);
    }

    public function test_the_chosen_view_stays_saved(): void
    {
        $this->photo($this->accommodation);

        $this->photos()
            ->set('view', AccommodationPhotos::GRID)
            ->assertSeeHtml('aspect-[4/3]');

        $this->photos()
            ->assertSet('view', AccommodationPhotos::GRID);
    }
}
