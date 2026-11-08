<?php

namespace Database\Seeders;

use App\Models\Property;
use Illuminate\Database\Seeder;

/**
 * Attaches sample photos from a local folder to the demo accommodation.
 * Call with: php artisan db:seed --class=DemoPhotoSeeder
 * The folder can be set via the env variable DEMO_PHOTOS_DIR.
 */
class DemoPhotoSeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::where('slug', env('DEMO_SLUG', 'duinhuisje-on-sea'))->first();

        if ($property === null) {
            $this->command?->warn('Demo accommodation not found.');

            return;
        }

        if ($property->getMedia('photos')->isNotEmpty()) {
            $this->command?->info("This accommodation already has photos.");

            return;
        }

        $folder = env('DEMO_PHOTOS_DIR', storage_path('demo-photos'));

        foreach (glob($folder.'/*.jpg') ?: [] as $path) {
            $property->addMedia($path)
                ->preservingOriginal()
                ->toMediaCollection('photos');

            $this->command?->info(basename($path).' added');
        }

        $this->command?->info($property->getMedia('photos')->count()." photos in total.");
    }
}
