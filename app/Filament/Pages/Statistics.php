<?php

namespace App\Filament\Pages;

use App\Filament\Statistics\DeviceChart;
use App\Filament\Statistics\ConversionFunnelChart;
use App\Filament\Statistics\OriginChart;
use App\Filament\Statistics\PageViewsChart;
use App\Filament\Statistics\StatisticsOverview;
use App\Filament\Statistics\LanguageChart;
use App\Filament\Statistics\TopPhotosChart;
use App\Filament\Statistics\ViewsPerAccommodationChart;
use BackedEnum;
use App\Support\Access;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Separate page with all visit and performance figures, alongside the Dashboard.
 *
 * Extends Dashboard purely for the widget grid, but gets its own route
 * ('/statistics'); the real Dashboard stays at '/'. The widgets below
 * deliberately live in the namespace App\Filament\Statistics and not in
 * App\Filament\Widgets — only the latter is discovered by the panel and
 * shown on the Dashboard, so the dashboard widgets (revenue,
 * calendar) and these statistics stay neatly separated.
 */
class Statistics extends Dashboard
{
    protected static string $routePath = '/statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return __('Statistics');
    }

    public static function canAccess(): bool
    {
        return Access::may(auth()->user(), 'statistics', Access::VIEW);
    }

    public function getTitle(): string|Htmlable
    {
        return __('Statistics');
    }

    public function getWidgets(): array
    {
        return [
            StatisticsOverview::class,
            PageViewsChart::class,
            ViewsPerAccommodationChart::class,
            ConversionFunnelChart::class,
            TopPhotosChart::class,
            DeviceChart::class,
            LanguageChart::class,
            OriginChart::class,
        ];
    }
}
