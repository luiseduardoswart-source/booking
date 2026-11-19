<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Filament\Statistics\Concerns\FiltersByAccommodation;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class LanguageChart extends ChartWidget
{
    use ChartColors;
    use FiltersByAccommodation;

    protected static ?int $sort = 7;

    public function getHeading(): string|Htmlable|null
    {
        return __('Visitors by language (30 days)');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = $this->events('page_view')
            ->whereNotNull('locale')
            ->selectRaw('locale, count(*) as count')
            ->groupBy('locale')
            ->orderByDesc('count')
            ->pluck('count', 'locale');

        return [
            'datasets' => [[
                'label' => __('Views'),
                'data' => $rows->values()->all(),
                'backgroundColor' => $this->categoricalColors($rows->count()),
                'borderWidth' => 0,
            ]],
            'labels' => $rows->keys()->map(fn ($l) => strtoupper((string) $l))->all(),
        ];
    }
}
