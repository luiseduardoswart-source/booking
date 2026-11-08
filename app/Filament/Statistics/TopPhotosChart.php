<?php

namespace App\Filament\Statistics;

use App\Filament\Statistics\Concerns\ChartColors;
use App\Models\ListingEvent;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class TopPhotosChart extends ChartWidget
{
    use ChartColors;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string|Htmlable|null
    {
        return __('Most viewed photos (30 days)');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $since = now()->subDays(29)->startOfDay();

        $rows = ListingEvent::where('type', 'photo_view')
            ->where('created_at', '>=', $since)
            ->whereNotNull('media_id')
            ->selectRaw('media_id, count(*) as count')
            ->groupBy('media_id')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'media_id');

        $media = Media::whereIn('id', $rows->keys())->get()->keyBy('id');

        $labels = $rows->keys()->map(function ($id) use ($media) {
            $item = $media[$id] ?? null;

            if (! $item) {
                return 'Foto #'.$id;
            }

            $label = $item->getCustomProperty('label');
            $accommodation = optional($item->model)->name;

            $text = trim(implode(' — ', array_filter([$label, $accommodation])));

            return $text !== '' ? $text : 'Foto #'.$id;
        })->all();

        return [
            'datasets' => [[
                'label' => __('Photo views'),
                'data' => $rows->values()->all(),
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
