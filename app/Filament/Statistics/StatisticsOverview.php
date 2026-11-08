<?php

namespace App\Filament\Statistics;

use App\Models\ListingEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatisticsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $views = ListingEvent::where('type', 'page_view')->where('created_at', '>=', $since)->count();
        $visitors = ListingEvent::where('type', 'page_view')->where('created_at', '>=', $since)
            ->distinct('session_id')->count('session_id');
        $quotes = ListingEvent::where('type', 'quote')->where('created_at', '>=', $since)->count();
        $bookings = ListingEvent::where('type', 'booking')->where('created_at', '>=', $since)->count();
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
