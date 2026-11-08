<?php

namespace Database\Seeders;

use App\Models\Amenity;
use Illuminate\Database\Seeder;

class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (Amenity::DEFAULT as $name => $icon) {
            Amenity::firstOrCreate(['name' => $name], ['icon' => $icon, 'category' => Amenity::defaultCategory($name)]);
        }
    }
}
