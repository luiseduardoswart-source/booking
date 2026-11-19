<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\FiltersByAccommodation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatisticsOverview extends StatsOverviewWidget
{
    use FiltersByAccommodation;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $views = $this->events('page_view')->count();
        $visitors = $this->events('page_view')->distinct('session_id')->count('session_id');
        $quotes = $this->events('quote')->count();
        $bookings = $this->events('booking')->count();
        $conversion = $views > 0 ? round($bookings / $views * 100, 1) : 0;

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
