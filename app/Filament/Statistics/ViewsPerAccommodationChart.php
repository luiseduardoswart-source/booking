<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Models\ListingEvent;
use App\Models\Property;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class ViewsPerAccommodationChart extends ChartWidget
{
    use ChartColors;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string|Htmlable|null
    {
        return __('Views per accommodation (30 days)');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $rows = ListingEvent::where('type', 'page_view')
            ->where('created_at', '>=', $since)
            ->whereNotNull('property_id')
            ->selectRaw('property_id, count(*) as count')
            ->groupBy('property_id')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'property_id');

        $names = Property::whereIn('id', $rows->keys())->pluck('name', 'id');

        return [
            'datasets' => [[
                'label' => __('Page views'),
                'data' => $rows->values()->all(),
                'backgroundColor' => self::PRIMARY,
                'borderRadius' => 4,
            ]],
            'labels' => $rows->keys()->map(fn ($id) => $names[$id] ?? ('#'.$id))->all(),
        ];
    }

    protected function getOptions(): ?array
    {
        return $this->withoutLegend();
    }
}
