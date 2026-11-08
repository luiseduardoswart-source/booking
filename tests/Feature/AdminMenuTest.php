<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Pages\Statistics;
use App\Filament\Resources\Amenities\AmenityResource;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\ChannelConnections\ChannelConnectionResource;
use App\Filament\Resources\Hosts\HostResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_is_in_the_agreed_order(): void
    {
        $expected = [
            1 => AmenityResource::class,
            2 => HostResource::class,
            3 => PropertyResource::class,
            // 4 = "Default accommodation settings" (separate navigation item, see below)
            5 => ChannelConnectionResource::class,
            6 => BookingResource::class,
            7 => PaymentResource::class,
            8 => ReviewResource::class,
        ];

        foreach ($expected as $sort => $class) {
            $this->assertSame($sort, $class::getNavigationSort(), $class);
        }

        $this->assertSame(9, Statistics::getNavigationSort());
        // Media (media manager) was added later, right before Settings
        $this->assertSame(10, \App\Filament\Pages\MediaManager::getNavigationSort());
        $this->assertSame(11, Settings::getNavigationSort());
    }

    public function test_default_accommodation_settings_are_on_spot_four(): void
    {
        $defaults = collect(PropertyResource::getNavigationItems())
            ->first(fn ($item) => $item->getLabel() === __('Default accommodation settings'));

        $this->assertNotNull($defaults);
        $this->assertSame(4, $defaults->getSort());
    }

    public function test_synchronization_is_called_now_channel_connections(): void
    {
        $this->assertSame('Kanaalkoppelingen', ChannelConnectionResource::getNavigationLabel());
    }

    public function test_statistics_page_has_a_own_route_with_all_charts(): void
    {
        $this->assertCount(8, (new Statistics)->getWidgets());

        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)->get('/admin/statistics')->assertOk()->assertSee(__('Statistics'));
    }

    public function test_dashboard_stays_exist_with_are_own_widgets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Administrator]);

        $this->actingAs($admin)->get('/admin')->assertOk();

        // The existing dashboard widgets are still discovered...
        $discovers = \Filament\Facades\Filament::getPanel('admin')->getWidgets();

        $this->assertContains(\App\Filament\Widgets\StatsOverview::class, $discovers);
        $this->assertContains(\App\Filament\Widgets\RevenueChart::class, $discovers);
        $this->assertContains(\App\Filament\Widgets\TodayAgenda::class, $discovers);

        // ...and the statistics widgets are not, so they only appear on the
        // Statistics page.
        foreach ((new Statistics)->getWidgets() as $statisticWidget) {
            $this->assertNotContains($statisticWidget, $discovers);
        }
    }
}
