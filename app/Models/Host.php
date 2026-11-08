<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'bio', 'bio_translations'])]
class Host extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    protected function casts(): array
    {
        return ['bio_translations' => 'array'];
    }

    /**
     * The bio in the visitor's language, falling back to the source text.
     */
    public function localizedBio(): ?string
    {
        return $this->localized('bio');
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')
            ->useDisk('public')
            ->singleFile()
            ->registerMediaConversions(function (?Media $media) {
                $this->addMediaConversion('thumb')
                    ->width(200)
                    ->height(200)
                    ->nonQueued();
            });
    }
}
