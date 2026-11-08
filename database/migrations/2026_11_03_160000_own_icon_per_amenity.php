<?php

use App\Models\Amenity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every default amenity gets its own icon (see AmenityIcons).
 *
 * A default amenity that still had the old default icon is set to
 * automatic: the site then shows the new icon from Amenity::DEFAULT. An icon
 * chosen by hand stays, only with the new name if the old icon
 * no longer exists. The same goes for the highlights of accommodations.
 */
return new class extends Migration
{
    /** Old icon name => the icon that replaces it now. */
    private const RENAMED = [
        'kitchen' => 'chef-hat',
        'parking' => 'square-parking',
        'fire' => 'flame',
        'sparkles' => 'broom',
        'pool' => 'waves-ladder',
        'bell-alert' => 'alarm-smoke',
        'first-aid' => 'first-aid-kit',
    ];

    /** The default icon of every default amenity before this change. */
    private const OLD_DEFAULT = [
        'Air conditioning' => 'snowflake',
        'Arcade games' => 'tv',
        'Baby bath' => 'crib',
        'Baby monitor' => 'crib',
        'Baby safety gates' => 'crib',
        'Babysitter recommendations' => 'crib',
        'Backyard' => 'sprout',
        'Baking sheet' => 'kitchen',
        'Barbecue utensils' => 'fire',
        'Bathtub' => 'pool',
        'Batting cage' => 'check',
        'BBQ grill' => 'fire',
        'Beach access' => 'sun',
        'Beach essentials' => 'sun',
        'Bed linens' => 'check',
        'Bidet' => 'check',
        'Bikes' => 'check',
        'Blender' => 'kitchen',
        'Board games' => 'check',
        'Boat slip' => 'pool',
        'Books and reading material' => 'check',
        'Bowling alley' => 'check',
        'Bread maker' => 'kitchen',
        'Breakfast' => 'kitchen',
        'Carbon monoxide alarm' => 'bell-alert',
        'Ceiling fan' => 'snowflake',
        'Changing table' => 'crib',
        "Children's bikes" => 'crib',
        "Children's books and toys" => 'crib',
        "Children's dinnerware" => 'crib',
        "Children's playroom" => 'crib',
        'Cleaning available during stay' => 'sparkles',
        'Cleaning products' => 'sparkles',
        'Climbing wall' => 'check',
        'Clothes drying rack' => 'washing-machine',
        'Clothing storage' => 'check',
        'Coffee' => 'kitchen',
        'Coffee maker' => 'kitchen',
        'Composting' => 'sprout',
        'Conditioner' => 'sparkles',
        'Cooking basics' => 'kitchen',
        'Crib' => 'crib',
        'Dedicated workspace' => 'check',
        'Dining table' => 'kitchen',
        'Dishes and silverware' => 'kitchen',
        'Dishwasher' => 'sparkles',
        'Doorman' => 'check',
        'Dryer' => 'washing-machine',
        'E-bike charger' => 'check',
        'Essentials' => 'check',
        'Ethernet connection' => 'wifi',
        'EV charger' => 'parking',
        'Exercise equipment' => 'check',
        'Extra pillows and blankets' => 'check',
        'Fire extinguisher' => 'bell-alert',
        'Fire pit' => 'fire',
        'Fireplace guards' => 'crib',
        'First aid kit' => 'first-aid',
        'Free parking off premises' => 'parking',
        'Free parking on premises' => 'parking',
        'Free street parking' => 'parking',
        'Freezer' => 'snowflake',
        'Game console' => 'tv',
        'Gated community' => 'check',
        'Gym' => 'check',
        'Hair dryer' => 'check',
        'Hammock' => 'sun',
        'Hangers' => 'check',
        'Heating' => 'fire',
        'High chair' => 'crib',
        'Home cinema' => 'tv',
        'Hot tub' => 'pool',
        'Hot water' => 'check',
        'Ice hockey rink' => 'snowflake',
        'Indoor fireplace' => 'fire',
        'Iron' => 'check',
        'Kayak' => 'pool',
        'Kettle' => 'kitchen',
        'Kitchen' => 'kitchen',
        'Kitchenette' => 'kitchen',
        'Lake access' => 'pool',
        'Laser tag' => 'check',
        'Laundromat nearby' => 'washing-machine',
        'Life-size games' => 'check',
        'Lift' => 'check',
        'Long-term stays allowed' => 'check',
        'Luggage dropoff allowed' => 'check',
        'Microwave' => 'kitchen',
        'Mini fridge' => 'kitchen',
        'Mini golf' => 'check',
        'Mosquito net' => 'check',
        'Outdoor dining area' => 'sun',
        'Outdoor furniture' => 'sun',
        'Outdoor kitchen' => 'kitchen',
        'Outdoor playground' => 'sun',
        'Outdoor shower' => 'sun',
        'Outlet covers' => 'crib',
        'Oven' => 'kitchen',
        'Paid parking off premises' => 'parking',
        'Paid parking on premises' => 'parking',
        'Piano' => 'check',
        'Ping pong table' => 'check',
        'Pocket wifi' => 'wifi',
        'Pool table' => 'check',
        'Portable fans' => 'snowflake',
        'Private entrance' => 'check',
        'Private living room' => 'check',
        'Record player' => 'tv',
        'Refrigerator' => 'kitchen',
        'Resort access' => 'check',
        'Rice cooker' => 'kitchen',
        'Room-darkening curtains' => 'check',
        'Safe' => 'check',
        'Sauna' => 'fire',
        'Shampoo' => 'sparkles',
        'Shower gel' => 'sparkles',
        'Single-level home' => 'check',
        'Skate ramp' => 'check',
        'Ski-in/ski-out' => 'snowflake',
        'Smoke detector' => 'bell-alert',
        'Solar panels' => 'sun',
        'Sound system' => 'tv',
        'Stove' => 'kitchen',
        'Sun loungers' => 'sun',
        'Swimming pool' => 'pool',
        'Table corner guards' => 'crib',
        'Terrace or balcony' => 'sun',
        'Theme room' => 'check',
        'Toaster' => 'kitchen',
        'Trash compactor' => 'check',
        'Travel cot' => 'crib',
        'TV' => 'tv',
        'Washing machine' => 'washing-machine',
        'Waste sorting' => 'sprout',
        'Waterfront' => 'pool',
        'Wifi' => 'wifi',
        'Window guards' => 'crib',
        'Wine glasses' => 'kitchen',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (Amenity::query()->whereNotNull('icon')->get() as $amenity) {
                if ((self::OLD_DEFAULT[$amenity->name] ?? null) === $amenity->icon) {
                    $amenity->update(['icon' => null]);
                } elseif (isset(self::RENAMED[$amenity->icon])) {
                    $amenity->update(['icon' => self::RENAMED[$amenity->icon]]);
                }
            }

            $this->highlights(self::RENAMED);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $back = array_flip(self::RENAMED);

            foreach (Amenity::query()->get() as $amenity) {
                if ($amenity->icon === null && isset(self::OLD_DEFAULT[$amenity->name])) {
                    $amenity->update(['icon' => self::OLD_DEFAULT[$amenity->name]]);
                } elseif (isset($back[$amenity->icon])) {
                    $amenity->update(['icon' => $back[$amenity->icon]]);
                }
            }

            $this->highlights($back);
        });
    }

    /** @param  array<string, string>  $renamed */
    private function highlights(array $renamed): void
    {
        foreach (DB::table('properties')->whereNotNull('highlights')->get(['id', 'highlights']) as $row) {
            $points = json_decode((string) $row->highlights, true);

            if (! is_array($points)) {
                continue;
            }

            $new = array_map(
                fn ($point) => is_array($point) && isset($renamed[$point['icon'] ?? '']) ? ['icon' => $renamed[$point['icon']]] + $point : $point,
                $points,
            );

            if ($new !== $points) {
                DB::table('properties')->where('id', $row->id)->update(['highlights' => json_encode($new, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
