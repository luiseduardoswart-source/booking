<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page of an accommodation shows the first ten amenities; the
 * rest sits behind "Show all amenities", per category.
 */
class AmenitiesOnThePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function accommodationWith(int $count): Property
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4,
            'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
        $accommodation->amenities()->attach(Amenity::query()->orderBy('id')->limit($count)->pluck('id'));

        return $accommodation;
    }

    /** @return array{0: string, 1: string} the list on the page and the window */
    private function share(string $html): array
    {
        $begin = strpos($html, __('What this place offers'));
        $window = strpos($html, 'id="amenities_dialog"');

        return [
            substr($html, $begin, ($window ?: strlen($html)) - $begin),
            $window === false ? '' : substr($html, $window, strpos($html, '</dialog>', $window) - $window),
        ];
    }

    public function test_after_ten_amenities_a_button_shows_the_rest(): void
    {
        $this->assertGreaterThan(Amenity::ON_THE_PAGE, Amenity::count());
        $accommodation = $this->accommodationWith(14);

        $html = $this->get('/en/strandhuis')->assertOk()->getContent();
        app()->setLocale('en'); // the language of the page

        $this->assertStringContainsString(__('Show all :count amenities', ['count' => 14]), $html);

        [$list, $window] = $this->share($html);
        $this->assertSame(Amenity::ON_THE_PAGE, substr_count($list, '<li class="flex items-center gap-3">'));
        $this->assertSame(14, substr_count($window, '<li class="flex items-center gap-4 py-5'));

        // Every amenity is in the window, including those that did not fit on the page.
        foreach ($accommodation->amenities as $amenity) {
            $this->assertStringContainsString(e($amenity->localizedName()), $window);
        }
    }

    public function test_at_ten_or_less_no_button_and_no_window(): void
    {
        $this->accommodationWith(Amenity::ON_THE_PAGE);

        $html = $this->get('/strandhuis')->assertOk()
            ->assertDontSee('amenities_dialog')
            ->getContent();
        app()->setLocale('en');
        $this->assertStringNotContainsString(__('Show all :count amenities', ['count' => Amenity::ON_THE_PAGE]), $html);

        [$list] = $this->share($html);
        $this->assertSame(Amenity::ON_THE_PAGE, substr_count($list, '<li class="flex items-center gap-3">'));
    }

    public function test_the_window_groups_per_category_in_the_fixed_order(): void
    {
        $names = ['Wifi', 'Shampoo', 'Hair dryer', 'Kitchen', 'Smoke detector'];
        $amenities = Amenity::query()->whereIn('name', $names)->get()
            ->sortBy(fn (Amenity $amenity) => array_search($amenity->name, $names, true))->values();
        $this->assertCount(count($names), $amenities);

        app()->setLocale('nl');
        $groups = Amenity::perCategory($amenities);

        // Bathroom, Safety, Internet, Kitchen: as in the picker in the admin.
        $this->assertSame([__('Bathroom'), __('Home safety'), __('Internet and office'), __('Kitchen and dining')], array_keys($groups));
        // Within a category the given order (the accommodation's own), not the alphabet.
        $this->assertSame(
            ['Shampoo', 'Haardroger'],
            $groups[__('Bathroom')]->map->localizedName()->all(),
        );
    }
}
