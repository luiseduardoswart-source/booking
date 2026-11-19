<?php

namespace App\Filament\Statistics;

use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Statistics\Concerns\FiltersByAccommodation;
use App\Models\Property;
use Filament\Widgets\Widget;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The most viewed photos of the last 30 days, shown as the photos themselves
 * with their description, accommodation and number of views. A view counts
 * when a photo stood in the photo viewer for a moment (see initPhotoViewer in
 * resources/js/app.js). Photos that were deleted since are left out.
 */
class TopPhotos extends Widget
{
    use FiltersByAccommodation;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.statistics.top-photos';

    private const LIMIT = 12;

    protected function getViewData(): array
    {
        $counts = $this->events('photo_view')
            ->join('media', 'media.id', '=', 'listing_events.media_id')
            ->selectRaw('listing_events.media_id, count(*) as views')
            ->groupBy('listing_events.media_id')
            ->orderByDesc('views')
            ->limit(self::LIMIT)
            ->pluck('views', 'media_id');

        $media = Media::query()->with('model')->whereIn('id', $counts->keys())->get()->keyBy('id');

        $photos = $counts->map(function (int $views, int $id) use ($media): ?array {
            $item = $media[$id] ?? null;
            $property = $item?->model instanceof Property ? $item->model : null;

            if ($item === null) {
                return null;
            }

            $video = Property::isVideo($item);

            return [
                'views' => $views,
                'video' => $video,
                'src' => $video ? $item->getUrl().'#t=0.1' : $item->getUrl('thumb'),
                'label' => Property::photoLabel($item),
                'property' => $property?->name,
                'link' => $property !== null && PropertyResource::canEdit($property) ? PropertyResource::getUrl('edit', ['record' => $property]) : null,
            ];
        })->filter()->values();

        return [
            'heading' => __('Most viewed photos (30 days)'),
            'photos' => $photos,
            'most' => max(1, (int) $photos->max('views')),
        ];
    }
}
