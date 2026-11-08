<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Models\ListingEvent;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class DeviceChart extends ChartWidget
{
    use ChartColors;

    protected static ?int $sort = 6;

    public function getHeading(): string|Htmlable|null
    {
        return __('Visitors by device (30 days)');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $rows = ListingEvent::where('type', 'page_view')
            ->where('created_at', '>=', $since)
            ->whereNotNull('device')
            ->selectRaw('device, count(*) as count')
            ->groupBy('device')
            ->pluck('count', 'device');

        $labelFolder = [
            'mobile' => __('Mobile'),
            'desktop' => __('Desktop'),
            'tablet' => __('Tablet'),
        ];

        return [
            'datasets' => [[
                'label' => __('Views'),
                'data' => $rows->values()->all(),
                'backgroundColor' => $this->categoricalColors($rows->count()),
                'borderWidth' => 0,
            ]],
            'labels' => $rows->keys()->map(fn ($d) => $labelFolder[$d] ?? $d)->all(),
        ];
    }
}
