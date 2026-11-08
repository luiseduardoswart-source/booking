<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Models\ListingEvent;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class PageViewsChart extends ChartWidget
{
    use ChartColors;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string|Htmlable|null
    {
        return __('Page views per day (30 days)');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $perDay = ListingEvent::where('type', 'page_view')
            ->where('created_at', '>=', $since)
            ->get(['created_at'])
            ->groupBy(fn ($e) => $e->created_at->format('Y-m-d'))
            ->map->count();

        $labels = [];
        $data = [];

        for ($i = 29; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->format('d-m');
            $data[] = $perDay[$day->format('Y-m-d')] ?? 0;
        }

        return [
            'datasets' => [[
                'label' => __('Page views'),
                'data' => $data,
                'fill' => 'start',
                'tension' => 0.3,
                'borderColor' => self::PRIMARY,
                'backgroundColor' => 'rgba(57, 135, 229, 0.15)',
                'borderWidth' => 2,
                'pointRadius' => 2,
                'pointHoverRadius' => 5,
                'pointBackgroundColor' => self::PRIMARY,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): ?array
    {
        return $this->withoutLegend();
    }
}
