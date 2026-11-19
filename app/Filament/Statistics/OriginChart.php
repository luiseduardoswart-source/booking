<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Filament\Statistics\Concerns\FiltersByAccommodation;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class OriginChart extends ChartWidget
{
    use ChartColors;
    use FiltersByAccommodation;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string|Htmlable|null
    {
        return __('Visitor sources (30 days)');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $base = $this->events('page_view');

        $direct = (clone $base)->whereNull('referrer_host')->count();

        $rows = (clone $base)
            ->whereNotNull('referrer_host')
            ->selectRaw('referrer_host, count(*) as count')
            ->groupBy('referrer_host')
            ->orderByDesc('count')
            ->limit(8)
            ->pluck('count', 'referrer_host');

        $labels = [];
        $data = [];

        if ($direct > 0) {
            $labels[] = __('Direct / no referrer');
            $data[] = $direct;
        }

        foreach ($rows as $host => $count) {
            $labels[] = $host;
            $data[] = $count;
        }

        return [
            'datasets' => [[
                'label' => __('Views'),
                'data' => $data,
                'backgroundColor' => self::PRIMARY,
                'borderRadius' => 4,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): ?array
    {
        return $this->withoutLegend();
    }
}
