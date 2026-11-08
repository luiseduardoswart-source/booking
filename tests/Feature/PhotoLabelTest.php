<?php

namespace Tests\Feature;

use App\Livewire\AccommodationPhotos;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PhotoLabelTest extends TestCase
{
    use RefreshDatabase;

    private function photoFile(): string
    {
        $img = imagecreatetruecolor(800, 600);
        imagefilledrectangle($img, 0, 0, 800, 600, imagecolorallocate($img, 100, 150, 200));
        $pad = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $pad, 85);
        imagedestroy($img);

        return $pad;
    }

    private function makePropertyWithPhoto(): array
    {
        Storage::fake('public');
        $property = Property::create([
            'name' => 'Labelhuis', 'slug' => 'labelhuis', 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true,
        ]);
        $media = $property->addMedia($this->photoFile())->toMediaCollection('photos');

        return [$property, $media];
    }

    public function test_label_is_per_photo_saved(): void
    {
        [$property, $media] = $this->makePropertyWithPhoto();

        Livewire::actingAs(\App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]))
            ->test(AccommodationPhotos::class, ['property' => $property])
            ->call('saveLabel', $media->id, 'Zwembad');

        $this->assertSame('Zwembad', $media->fresh()->getCustomProperty('label'));
    }

    public function test_empty_label_clears_the_custom_property(): void
    {
        [$property, $media] = $this->makePropertyWithPhoto();
        $media->setCustomProperty('label', 'Oud')->save();

        Livewire::actingAs(\App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]))
            ->test(AccommodationPhotos::class, ['property' => $property])
            ->call('saveLabel', $media->id, '   ');

        $this->assertNull($media->fresh()->getCustomProperty('label'));
    }

    public function test_label_survives_the_save_of_the_form(): void
    {
        [$property, $media] = $this->makePropertyWithPhoto();
        $property->update(['country' => 'NL', 'cancellation_policy' => 'moderate']);
        $media->setCustomProperty('label', 'Zwembad')->save();

        $admin = \App\Models\User::factory()->create(['role' => \App\Enums\UserRole::Administrator]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\Properties\Pages\EditProperty::class, ['record' => $property->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Zwembad', $property->fresh()->getFirstMedia('photos')->getCustomProperty('label'));
    }

    public function test_label_appears_on_the_detail_page(): void
    {
        [$property, $media] = $this->makePropertyWithPhoto();
        $media->setCustomProperty('label', 'Uitzicht op zee')->save();

        $this->withSession(['locale' => 'nl'])
            ->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('Uitzicht op zee');
    }

    public function test_own_code_is_in_head_and_body_placed(): void
    {
        [$property] = $this->makePropertyWithPhoto();
        \App\Models\Setting::set('frontend', 'head_code', '<meta name="mijn-head" content="x">');
        \App\Models\Setting::set('frontend', 'body_code', '<script>window.__mijnBody = 1;</script>');

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('<meta name="mijn-head" content="x">', false)
            ->assertSee('window.__mijnBody = 1;', false);
    }

    public function test_chosen_colors_are_in_the_layout_injected(): void
    {
        [$property] = $this->makePropertyWithPhoto();
        \App\Models\Setting::set('frontend', 'accent_color', '#123456');
        \App\Models\Setting::set('frontend', 'label_color', '#654321');

        $this->get("/{$property->slug}")
            ->assertOk()
            ->assertSee('--color-label: #654321', false)
            ->assertSee('.button-accent', false)
            ->assertSee('#123456', false);
    }
}
