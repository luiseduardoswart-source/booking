<?php

namespace App\Livewire;

use App\Filament\Pages\MediaManager;
use App\Filament\Resources\Properties\PropertyResource;
use App\Filament\Support\TranslationFields;
use App\Models\Property;
use App\Support\Languages;
use App\Support\Translations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The Photos tab of an accommodation: drag in photos, set the order,
 * give a label, edit and delete. Every action is saved immediately and
 * independently of the form's Save button. The label is a Spatie custom
 * property and appears on the site at the top left of the photo.
 */
class AccommodationPhotos extends Component
{
    use WithFileUploads;

    public const LIST_MODE = 'list';

    public const GRID = 'grid';

    public Property $property;

    #[Session]
    public string $view = self::LIST_MODE;

    /** One photo at a time: the browser sends them one after another, so a long series does not stall. */
    public ?UploadedFile $upload = null;

    public function mount(Property $property): void
    {
        $this->property = $property;
    }

    public function updatedUpload(): void
    {
        $this->checkPermission();

        // A video (mp4 or webm, max 100 MB) or a photo (max 25 MB). Another
        // video format, such as .mov from an iPhone, is refused by the video rule.
        $isVideo = str_starts_with((string) $this->upload?->getMimeType(), 'video/');

        $this->validate(
            ['upload' => ['required', $isVideo
                ? File::types(Property::VIDEO_EXTENSIONS)->max(Property::MAX_VIDEO_KB)
                : File::image()->max(Property::MAX_PHOTO_KB)]],
            attributes: ['upload' => $this->upload?->getClientOriginalName() ?? __('Photo')],
        );

        if ($this->photos()->count() >= Property::MAX_PHOTOS) {
            $this->addError('upload', __('Maximum :count photos per accommodation.', ['count' => Property::MAX_PHOTOS]));

            return;
        }

        $this->property->addPhoto($this->upload);
        $this->upload = null;
    }

    /** Renderless: nothing changes in the list, so the checkmark does not have to wait for a full re-render. */
    #[Renderless]
    public function saveLabel(int $mediaId, ?string $label, ?string $locale = null): void
    {
        $this->checkPermission();
        abort_unless($locale === null || in_array($locale, Languages::available(), true), 404);

        $media = $this->photo($mediaId);

        if ($media === null) {
            return;
        }

        $label = trim((string) $label);
        $label = $label !== '' ? $label : null;

        if ($locale === null) {
            $media->setCustomProperty('label', $label);
        } else {
            $translations = $media->getCustomProperty('label_translations', []);
            $translations[$locale] = $label;
            $media->setCustomProperty('label_translations', array_filter($translations));
        }

        $media->save();
    }

    /** Fills in the empty languages of all labels and saves them immediately, like the rest of this tab. */
    public function translateLabels(): void
    {
        $this->checkPermission();

        TranslationFields::notify(function (): int {
            $count = 0;

            foreach ($this->photos() as $media) {
                $before = $media->getCustomProperty('label_translations', []);
                $after = Translations::fillIn((string) $media->getCustomProperty('label'), $before, null, $count);

                if ($after !== $before) {
                    $media->setCustomProperty('label_translations', $after)->save();
                }
            }

            return $count;
        }, TranslationFields::RESULT);
    }

    /** Called by wire:sort with the dragged photo and its new position. */
    public function move(int $mediaId, int $position): void
    {
        $this->checkPermission();

        if ($this->photo($mediaId) === null) {
            return;
        }

        $order = array_values(array_diff($this->photos()->modelKeys(), [$mediaId]));
        array_splice($order, max(0, $position), 0, [$mediaId]);

        Media::setNewOrder($order);
    }

    public function delete(int $mediaId): void
    {
        $this->checkPermission();

        $this->photo($mediaId)?->delete();
    }

    private function checkPermission(): void
    {
        abort_unless(PropertyResource::canEdit($this->property), 403);
    }

    /** @return Collection<int, Media> always fresh: otherwise the relation keeps the old list */
    private function photos(): Collection
    {
        return $this->property->unsetRelation('media')->getMedia('photos');
    }

    /** Only a photo of this accommodation, never something else via a tampered id. */
    private function photo(int $mediaId): ?Media
    {
        return $this->photos()->firstWhere('id', $mediaId);
    }

    public function render(): View
    {
        return view('livewire.accommodation-photos', [
            'photos' => $this->photos(),
            'mayPhotosEdit' => MediaManager::canAccess(),
            'languages' => array_values(array_diff(Languages::active(), [Languages::SOURCE])),
        ]);
    }
}
