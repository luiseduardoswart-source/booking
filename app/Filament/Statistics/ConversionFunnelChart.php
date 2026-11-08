<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Models\ListingEvent;
use App\Models\Property;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

class ConversionFunnelChart extends ChartWidget
{
    use ChartColors;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string|Htmlable|null
    {
        return __('Conversion per accommodation: views → price requests → bookings (30 days)');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $topIds = ListingEvent::where('type', 'page_view')
            ->where('created_at', '>=', $since)
            ->whereNotNull('property_id')
            ->selectRaw('property_id, count(*) as count')
            ->groupBy('property_id')
            ->orderByDesc('count')
            ->limit(6)
            ->pluck('property_id');

        if ($topIds->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        $counts = ListingEvent::whereIn('property_id', $topIds)
            ->where('created_at', '>=', $since)
            ->whereIn('type', ['page_view', 'quote', 'booking'])
            ->selectRaw('property_id, type, count(*) as count')
            ->groupBy('property_id', 'type')
            ->get()
            ->groupBy('property_id');

        $names = Property::whereIn('id', $topIds)->pluck('name', 'id');

        $labels = [];
        $views = [];
        $quotes = [];
        $bookings = [];

        foreach ($topIds as $id) {
            $perType = $counts[$id] ?? collect();
            $count = fn (string $type) => (int) (optional($perType->firstWhere('type', $type))->count ?? 0);

            $labels[] = $names[$id] ?? ('#'.$id);
            $views[] = $count('page_view');
            $quotes[] = $count('quote');
            $bookings[] = $count('booking');
        }

        return [
            'datasets' => [
                ['label' => __('Views'), 'data' => $views, 'backgroundColor' => self::FUNNEL[0], 'borderRadius' => 4],
                ['label' => __('Price requests'), 'data' => $quotes, 'backgroundColor' => self::FUNNEL[1], 'borderRadius' => 4],
                ['label' => __('Bookings'), 'data' => $bookings, 'backgroundColor' => self::FUNNEL[2], 'borderRadius' => 4],
            ],
            'labels' => $labels,
        ];
    }
}
