<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Database\Seeder;

class DemoPropertySeeder extends Seeder
{
    public function run(): void
    {
        $property = Property::firstOrCreate(
            ['slug' => 'dune-cottage-by-the-sea'],
            [
                'name' => 'Dune Cottage by the Sea',
                'type' => 'holiday_home',
                'active' => true,
                'short_description' => 'Cosy detached cottage 300 metres from the beach, with a large garden and terrace.',
                'description' => '<p>This charming cottage sits in a unique spot: in the middle of the dunes and only a 300-metre walk from the beach. It was recently renovated and has every comfort.</p><p>The bright living room has large windows overlooking the dunes. The open kitchen is fully equipped, and on the sunny terrace you can enjoy quiet evenings with only the sound of the sea.</p>',
                'house_rules' => "No smoking indoors.\nNo parties or events.\nPets by arrangement.",
                'address' => 'Duinweg 12',
                'postal_code' => '2202 XX',
                'city' => 'Noordwijk',
                'country' => 'NL',
                'latitude' => 52.2411,
                'longitude' => 4.4300,
                'max_guests' => 4,
                'bedrooms' => 2,
                'beds' => 3,
                'bathrooms' => 1,
                'base_price_cents' => 12500,
                'weekend_price_cents' => 14500,
                'cleaning_fee_cents' => 6500,
                'tourist_tax_cents' => 285,
                'deposit_cents' => 15000,
                'included_guests' => 2,
                'extra_guest_fee_cents' => 1250,
                'check_in_from' => '15:00',
                'check_in_until' => '20:00',
                'check_out_until' => '10:30',
                'meta_title' => 'Dune Cottage by the Sea — holiday home in Noordwijk',
                'meta_description' => 'Cosy holiday cottage in the dunes of Noordwijk, 300 m from the beach. Book directly online.',
            ],
        );

        $property->amenities()->syncWithoutDetaching(
            Amenity::whereIn('name', ['Wifi', 'Kitchen', 'TV', 'Backyard', 'Terrace or balcony', 'Free parking on premises', 'Heating'])->pluck('id'),
        );

        $property->discounts()->firstOrCreate(['min_nights' => 7], ['percentage' => 10]);

        $property->rateSeasons()->firstOrCreate(
            ['name' => 'High season 2026'],
            [
                'start_date' => '2026-07-01',
                'end_date' => '2026-08-31',
                'price_cents' => 16500,
                'weekend_price_cents' => 18500,
            ],
        );

        $city = Property::firstOrCreate(
            ['slug' => 'city-apartment-utrecht'],
            [
                'name' => 'City Apartment Utrecht',
                'type' => 'apartment',
                'active' => true,
                'short_description' => 'Bright apartment in the centre, within walking distance of the Oudegracht.',
                'description' => '<p>This spacious city apartment is on the second floor of a characteristic building in the heart of Utrecht. The Oudegracht, shops and restaurants are literally around the corner.</p><p>The apartment has a modern open kitchen, a comfortable living room with lots of light and a quiet bedroom at the back.</p>',
                'house_rules' => "No smoking.\nNo parties.\nQuiet after 22:00 (neighbours upstairs).",
                'address' => 'Oudegracht 200',
                'postal_code' => '3511 NR',
                'city' => 'Utrecht',
                'country' => 'NL',
                'latitude' => 52.0894,
                'longitude' => 5.1213,
                'max_guests' => 2,
                'bedrooms' => 1,
                'beds' => 1,
                'bathrooms' => 1,
                'base_price_cents' => 9500,
                'weekend_price_cents' => 11500,
                'cleaning_fee_cents' => 4500,
                'tourist_tax_cents' => 350,
                'deposit_cents' => 10000,
                'check_in_from' => '14:00',
                'check_in_until' => '21:00',
                'check_out_until' => '11:00',
                'meta_title' => 'City Apartment Utrecht — stay on the Oudegracht',
                'meta_description' => 'Bright apartment in the heart of Utrecht, within walking distance of the Oudegracht. Book directly online.',
            ],
        );

        $city->amenities()->syncWithoutDetaching(
            Amenity::whereIn('name', ['Wifi', 'Kitchen', 'TV', 'Heating', 'Washing machine', 'Dishwasher'])->pluck('id'),
        );

        $city->discounts()->firstOrCreate(['min_nights' => 5], ['percentage' => 8]);

        // Each accommodation has its own host.
        $luis = \App\Models\Host::firstOrCreate(
            ['name' => 'Luis'],
            ['bio' => 'Has enjoyed hosting guests on the coast for 10 years. Always happy to share tips about the area.'],
        );

        $family = \App\Models\Host::firstOrCreate(
            ['name' => 'The Jansen family'],
            ['bio' => 'We live just around the corner and are happy to welcome you in person.'],
        );

        $property->hosts()->sync([$family->id]);
        $city->hosts()->sync([$luis->id]);
    }
}
