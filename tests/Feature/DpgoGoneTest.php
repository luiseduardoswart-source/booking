<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use App\Services\NullDynamicPricingProvider;
use App\Services\PricingServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DPGO has no API to fetch prices; the choice is gone, and whoever had
 * chosen it follows the default.
 */
class DpgoGoneTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_07_120000_dpgo_gone.php');
    }

    public function test_dpgo_is_not_more_in_the_choice_list(): void
    {
        $this->assertSame(['none', 'pricelabs', 'beyond', 'wheelhouse'], array_keys(PricingServices::options()));
        $this->assertFileDoesNotExist(app_path('Services/DpgoProvider.php'));
    }

    public function test_who_dpgo_had_follows_the_default_and_the_key_disappears(): void
    {
        $accommodation = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'external_listing_id' => 'X1',
        ]);
        $other = Property::create([
            'name' => 'Stadshuis', 'slug' => 'stadshuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'pricing_service' => 'pricelabs',
        ]);
        DB::table('properties')->where('id', $accommodation->id)->update(['pricing_service' => 'dpgo']);
        DB::table('properties')->where('is_master', true)->update(['pricing_service' => 'dpgo']);
        Setting::set('prices', 'dpgo_api_key', 'old-key');

        $this->migration()->up();
        Setting::forgets();

        $this->assertNull($accommodation->fresh()->pricing_service);
        $this->assertSame('none', Property::master()->pricing_service);
        $this->assertSame('pricelabs', $other->fresh()->pricing_service);
        $this->assertNull(Setting::get('prices', 'dpgo_api_key'));

        // And so no service anymore for this accommodation.
        $this->assertInstanceOf(NullDynamicPricingProvider::class, app(PricingServices::class)->before($accommodation->fresh()));
    }
}
