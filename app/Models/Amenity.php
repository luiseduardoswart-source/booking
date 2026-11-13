<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\AmenityIcons;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Lang;

#[Fillable(['name', 'name_translations', 'icon', 'category'])]
class Amenity extends Model
{
    use HasTranslations;

    protected function casts(): array
    {
        return ['name_translations' => 'array'];
    }

    /**
     * The name in the visitor's language.
     *
     * Order: a self-entered translation, otherwise the built-in translation
     * from the language files (covers the default amenities), otherwise simply the
     * English name — __() returns the key if there is nothing.
     */
    public function localizedName(): string
    {
        return (string) $this->localized('name');
    }

    /** The default amenities are in the language files under their English name, also in nl.json. */
    public function translateViaLanguageFile(string $field): bool
    {
        return $field === 'name';
    }

    /**
     * This is what a default amenity was called until the names became English. The
     * earlier migrations work with these names, because that is how they were in
     * the database then.
     */
    public static function dutchName(string $name): string
    {
        return Lang::get($name, [], 'nl');
    }

    /**
     * The amenities every installation gets, with their icon. Deliberately a
     * broad list: a host would rather tick things off than have to type
     * everything in. Names are English (the source language, see Languages::SOURCE); the translations
     * are in the language files, see localizedName().
     */
    public const DEFAULT = [
        'Air conditioning' => 'air-conditioning',
        'Arcade games' => 'joystick',
        'Baby bath' => 'baby',
        'Baby monitor' => 'baby-monitor',
        'Baby safety gates' => 'fence',
        'Babysitter recommendations' => 'hand-heart',
        'Backyard' => 'trees',
        'Baking sheet' => 'cookie',
        'Barbecue utensils' => 'grill-fork',
        'Bathtub' => 'bath',
        'Batting cage' => 'ball-baseball',
        'BBQ grill' => 'grill',
        'Beach access' => 'beach',
        'Beach essentials' => 'umbrella',
        'Bed linens' => 'bed-double',
        'Bidet' => 'toilet',
        'Bikes' => 'bike',
        'Blender' => 'blender',
        'Board games' => 'dices',
        'Boat slip' => 'anchor',
        'Books and reading material' => 'book-open',
        'Bowling alley' => 'bowling',
        'Bread maker' => 'bread-maker',
        'Breakfast' => 'egg-fried',
        'Carbon monoxide alarm' => 'siren',
        'Ceiling fan' => 'propeller',
        'Changing table' => 'diaper',
        'Children\'s bikes' => 'scooter',
        'Children\'s books and toys' => 'horse-toy',
        'Children\'s dinnerware' => 'bowl-spoon',
        'Children\'s playroom' => 'toy-brick',
        'Cleaning available during stay' => 'broom',
        'Cleaning products' => 'spray-can',
        'Climbing wall' => 'brick-wall',
        'Clothes drying rack' => 'drying-rack',
        'Clothing storage' => 'shirt',
        'Coffee' => 'bean',
        'Coffee maker' => 'coffee',
        'Composting' => 'sprout',
        'Conditioner' => 'perfume-bottle',
        'Cooking basics' => 'soup',
        'Crib' => 'crib',
        'Dedicated workspace' => 'desk',
        'Dining table' => 'dining-table',
        'Dishes and silverware' => 'utensils',
        'Dishwasher' => 'dishwasher',
        'Doorman' => 'concierge-bell',
        'Dryer' => 'dryer',
        'E-bike charger' => 'plug-zap',
        'Essentials' => 'toilet-paper',
        'Ethernet connection' => 'ethernet-port',
        'EV charger' => 'ev-charger',
        'Exercise equipment' => 'dumbbell',
        'Extra pillows and blankets' => 'pillow',
        'Fire extinguisher' => 'fire-extinguisher',
        'Fire pit' => 'campfire',
        'Fireplace guards' => 'brick-wall-fire',
        'First aid kit' => 'first-aid-kit',
        'Free parking off premises' => 'circle-parking',
        'Free parking on premises' => 'square-parking',
        'Free street parking' => 'car',
        'Freezer' => 'snowflake',
        'Game console' => 'gamepad-2',
        'Gated community' => 'building-community',
        'Gym' => 'biceps-flexed',
        'Hair dryer' => 'hair-dryer',
        'Hammock' => 'hammock',
        'Hangers' => 'hanger',
        'Heating' => 'heater',
        'High chair' => 'high-chair',
        'Home cinema' => 'clapperboard',
        'Hot tub' => 'bubbles',
        'Hot water' => 'droplets',
        'Ice hockey rink' => 'hockey-stick',
        'Indoor fireplace' => 'flame',
        'Iron' => 'ironing',
        'Kayak' => 'kayak',
        'Kettle' => 'teapot',
        'Kitchen' => 'chef-hat',
        'Kitchenette' => 'tools-kitchen-2',
        'Lake access' => 'sailboat',
        'Laser tag' => 'crosshair',
        'Laundromat nearby' => 'wash',
        'Life-size games' => 'chess-knight',
        'Lift' => 'elevator',
        'Long-term stays allowed' => 'calendar-days',
        'Luggage dropoff allowed' => 'luggage',
        'Microwave' => 'microwave',
        'Mini fridge' => 'fridge',
        'Mini golf' => 'golf',
        'Mosquito net' => 'bug',
        'Outdoor dining area' => 'hand-platter',
        'Outdoor furniture' => 'armchair',
        'Outdoor kitchen' => 'grill-spatula',
        'Outdoor playground' => 'ferris-wheel',
        'Outdoor shower' => 'shower-head',
        'Outlet covers' => 'outlet',
        'Oven' => 'oven',
        'Paid parking off premises' => 'parking-meter',
        'Paid parking on premises' => 'ticket',
        'Piano' => 'piano',
        'Ping pong table' => 'ping-pong',
        'Pocket wifi' => 'router',
        'Pool table' => 'sport-billiard',
        'Portable fans' => 'fan',
        'Private entrance' => 'through-open',
        'Private living room' => 'sofa',
        'Record player' => 'turntable',
        'Refrigerator' => 'refrigerator',
        'Resort access' => 'hotel',
        'Rice cooker' => 'bowl-chopsticks',
        'Room-darkening curtains' => 'blinds',
        'Safe' => 'vault',
        'Sauna' => 'thermometer-sun',
        'Shampoo' => 'soap-dispenser-droplet',
        'Shower gel' => 'bottle',
        'Single-level home' => 'house',
        'Skate ramp' => 'skateboarding',
        'Ski-in/ski-out' => 'mountain-snow',
        'Smoke detector' => 'alarm-smoke',
        'Solar panels' => 'solar-panel',
        'Sound system' => 'speaker',
        'Stove' => 'cooking-pot',
        'Sun loungers' => 'sun-lounger',
        'Swimming pool' => 'waves-ladder',
        'Table corner guards' => 'corner-guard',
        'Terrace or balcony' => 'sun',
        'Theme room' => 'drama',
        'Toaster' => 'toaster',
        'Trash compactor' => 'trash-2',
        'Travel cot' => 'travel-cot',
        'TV' => 'tv',
        'Washing machine' => 'washing-machine',
        'Waste sorting' => 'recycle',
        'Waterfront' => 'waves',
        'Wifi' => 'wifi',
        'Window guards' => 'window',
        'Wine glasses' => 'wine',
    ];

    /** Where an amenity without a category goes, such as a self-added one. */
    public const OTHER = 'other';

    /** The categories in the picker, in this order. Labels are translation keys. */
    public const CATEGORIES = [
        'bathroom' => 'Bathroom',
        'bedroom' => 'Bedroom and laundry',
        'entertainment' => 'Entertainment',
        'family' => 'Family',
        'climate' => 'Heating and cooling',
        'safety' => 'Home safety',
        'internet' => 'Internet and office',
        'kitchen' => 'Kitchen and dining',
        'location' => 'Location features',
        'outside' => 'Outdoor',
        'parking' => 'Parking and facilities',
        'services' => 'Services',
        self::OTHER => 'Other',
    ];

    /** The category of each default amenity. */
    public const DEFAULT_CATEGORY = [
        'bathroom' => ['Bathtub', 'Bidet', 'Cleaning products', 'Conditioner', 'Hair dryer', 'Hot water', 'Shampoo', 'Shower gel'],
        'bedroom' => ['Bed linens', 'Clothes drying rack', 'Clothing storage', 'Dryer', 'Essentials', 'Extra pillows and blankets', 'Hangers', 'Iron', 'Mosquito net', 'Room-darkening curtains', 'Safe', 'Washing machine'],
        'entertainment' => ['Arcade games', 'Batting cage', 'Books and reading material', 'Bowling alley', 'Climbing wall', 'Exercise equipment', 'Game console', 'Home cinema', 'Ice hockey rink', 'Laser tag', 'Life-size games', 'Mini golf', 'Piano', 'Ping pong table', 'Pool table', 'Record player', 'Skate ramp', 'Sound system', 'Theme room', 'TV'],
        'family' => ['Baby bath', 'Baby monitor', 'Baby safety gates', 'Babysitter recommendations', 'Board games', 'Changing table', 'Children\'s bikes', 'Children\'s books and toys', 'Children\'s dinnerware', 'Children\'s playroom', 'Crib', 'Fireplace guards', 'High chair', 'Outdoor playground', 'Outlet covers', 'Table corner guards', 'Travel cot', 'Window guards'],
        'climate' => ['Air conditioning', 'Ceiling fan', 'Heating', 'Indoor fireplace', 'Portable fans'],
        'safety' => ['Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit', 'Smoke detector'],
        'internet' => ['Dedicated workspace', 'Ethernet connection', 'Pocket wifi', 'Wifi'],
        'kitchen' => ['Baking sheet', 'Barbecue utensils', 'Blender', 'Bread maker', 'Coffee', 'Coffee maker', 'Cooking basics', 'Dining table', 'Dishes and silverware', 'Dishwasher', 'Freezer', 'Kettle', 'Kitchen', 'Kitchenette', 'Microwave', 'Mini fridge', 'Oven', 'Refrigerator', 'Rice cooker', 'Stove', 'Toaster', 'Trash compactor', 'Wine glasses'],
        'location' => ['Beach access', 'Gated community', 'Lake access', 'Laundromat nearby', 'Private entrance', 'Resort access', 'Ski-in/ski-out', 'Waterfront'],
        'outside' => ['Backyard', 'BBQ grill', 'Beach essentials', 'Bikes', 'Boat slip', 'Fire pit', 'Hammock', 'Kayak', 'Outdoor dining area', 'Outdoor furniture', 'Outdoor kitchen', 'Outdoor shower', 'Sun loungers', 'Terrace or balcony'],
        'parking' => ['Composting', 'E-bike charger', 'EV charger', 'Free parking off premises', 'Free parking on premises', 'Free street parking', 'Gym', 'Hot tub', 'Lift', 'Paid parking off premises', 'Paid parking on premises', 'Private living room', 'Sauna', 'Single-level home', 'Solar panels', 'Swimming pool', 'Waste sorting'],
        'services' => ['Breakfast', 'Cleaning available during stay', 'Doorman', 'Long-term stays allowed', 'Luggage dropoff allowed'],
    ];

    /** The category that belongs to a default name, otherwise null. */
    public static function defaultCategory(string $name): ?string
    {
        foreach (self::DEFAULT_CATEGORY as $category => $names) {
            if (in_array($name, $names, true)) {
                return $category;
            }
        }

        return null;
    }

    /** The category in the picker: the set one, otherwise Other. */
    public function resolvedCategory(): string
    {
        return array_key_exists((string) $this->category, self::CATEGORIES) ? $this->category : self::OTHER;
    }

    /**
     * This many appear on the accommodation page; the rest is behind
     * "Show all amenities". Two columns of five, as guests of
     * Airbnb are used to.
     */
    public const ON_THE_PAGE = 10;

    /**
     * Amenities per category, in the picker's order of categories; within a
     * category in the order given (the accommodation's own order, see
     * Property::amenities()). Empty categories are dropped.
     *
     * @param  iterable<Amenity>  $amenities
     * @return array<string, \Illuminate\Support\Collection<int, Amenity>> label => amenities
     */
    public static function perCategory(iterable $amenities): array
    {
        $groups = collect($amenities)->groupBy(fn (Amenity $amenity) => $amenity->resolvedCategory());
        $perCategory = [];

        foreach (self::categoryOptions() as $key => $label) {
            if ($groups->has($key)) {
                $perCategory[$label] = $groups[$key]->values();
            }
        }

        return $perCategory;
    }

    /** @return array<string, string> key => label in the current language */
    public static function categoryOptions(): array
    {
        return array_map(fn (string $label) => __($label), self::CATEGORIES);
    }

    /**
     * Icons that belong to no default amenity but can still be chosen:
     * icon => label (translation key). The others get the name of their amenity.
     */
    public const EXTRA_ICONS = [
        'check' => 'Tick (default)',
        'paw' => 'Pets',
    ];

    /**
     * All icons to choose from (see AmenityIcons), with the name of the
     * amenity the icon belongs to, in the current language.
     *
     * @return array<string, string> icon => label, sorted by label
     */
    public static function iconOptions(): array
    {
        $amenity = array_flip(self::DEFAULT);

        return collect(AmenityIcons::all())
            ->keys()
            ->mapWithKeys(fn (string $icon) => [$icon => __($amenity[$icon] ?? self::EXTRA_ICONS[$icon] ?? $icon)])
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->all();
    }

    /**
     * Like iconOptions(), but with the icon itself for a select with allowHtml().
     *
     * @return array<string, string> icon => HTML
     */
    public static function iconChoices(): array
    {
        return collect(static::iconOptions())->map(fn (string $label, string $icon) => AmenityIcons::option($icon, $label))->all();
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class);
    }

    /**
     * Icon for the website: explicitly set, otherwise recognised by name,
     * otherwise a tick.
     */
    public function iconName(): string
    {
        return $this->icon ?: (self::DEFAULT[$this->name] ?? 'check');
    }

    /** Longer does not fit in the column. */
    public const MAX_NAME = 255;

    /**
     * The names from a pasted list, one per line: without empty lines and
     * without duplicates (case is ignored).
     *
     * @return list<string>
     */
    public static function namesFromList(string $list): array
    {
        return collect(preg_split('/\R/u', $list) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values()
            ->all();
    }

    /**
     * Creates an amenity for every name that does not exist yet. The icon stays
     * empty, so it is recognised by name (see iconName()).
     *
     * @return array{added: int, skipped: int}
     */
    public static function addFromList(string $list): array
    {
        $existing = static::query()->pluck('name')->map(fn (string $name) => mb_strtolower($name))->flip();
        $added = 0;
        $skipped = 0;

        foreach (static::namesFromList($list) as $name) {
            // createOrFirst also catches what the database sees as equal, such as
            // "Cafe" and "Café" with a collation that ignores accents.
            if ($existing->has(mb_strtolower($name))
                || ! static::query()->createOrFirst(['name' => $name], ['category' => static::defaultCategory($name)])->wasRecentlyCreated) {
                $skipped++;

                continue;
            }

            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }
}
