<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\FiltersByAccommodation;
use App\Support\ListingStatistics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatisticsOverview extends StatsOverviewWidget
{
    use FiltersByAccommodation;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        ['views' => $views, 'visitors' => $visitors, 'quotes' => $quotes, 'bookings' => $bookings, 'conversion' => $conversion]
            = ListingStatistics::summary($this->accommodationId());

        $number = fn (int $n) => number_format($n, 0, ',', '.');

        return [
            Stat::make(__('Page views (30 days)'), $number($views)),
            Stat::make(__('Unique visitors'), $number($visitors)),
            Stat::make(__('Price requests'), $number($quotes)),
            Stat::make(__('Bookings'), $number($bookings))
                ->description($conversion.'% '.__('conversion')),
        ];
    }
}
