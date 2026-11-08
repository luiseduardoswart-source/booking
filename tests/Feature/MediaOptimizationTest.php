<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(): Property
    {
        return Property::create([
            'name' => 'Fotohuis',
            'slug' => 'fotohuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 2,
            'active' => true,
        ]);
    }

    private function grondePhoto(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, 120, 160, 200));
        $pad = tempnam(sys_get_temp_dir(), 'photo').'.jpg';
        imagejpeg($img, $pad, 90);
        imagedestroy($img);

        return $pad;
    }

    public function test_original_is_shrunk_as_the_setting_on_is(): void
    {
        Storage::fake('public');
        Setting::set('media', 'original_shrink', '1');
        Setting::set('media', 'original_max_px', '1000');

        $property = $this->makeProperty();
        $media = $property->addMedia($this->grondePhoto(2000, 1500))->toMediaCollection('photos');

        [$width, $height] = getimagesize($media->getPath());
        $this->assertLessThanOrEqual(1000, $width);
        $this->assertLessThanOrEqual(1000, $height);
        $this->assertSame(1000, $width); // 2000x1500 → 1000x750
    }

    public function test_original_stays_full_as_the_setting_off_is(): void
    {
        Storage::fake('public');
        Setting::set('media', 'original_shrink', '0');

        $property = $this->makeProperty();
        $media = $property->addMedia($this->grondePhoto(2000, 1500))->toMediaCollection('photos');

        [$width] = getimagesize($media->getPath());
        $this->assertSame(2000, $width);
    }

    public function test_small_photo_is_not_touched(): void
    {
        Storage::fake('public');
        Setting::set('media', 'original_shrink', '1');
        Setting::set('media', 'original_max_px', '2560');

        $property = $this->makeProperty();
        $media = $property->addMedia($this->grondePhoto(800, 600))->toMediaCollection('photos');

        [$width, $height] = getimagesize($media->getPath());
        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
    }
}
