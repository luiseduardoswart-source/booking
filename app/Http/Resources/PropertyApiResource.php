<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'city' => $this->city,
            'country' => $this->country,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'max_guests' => $this->max_guests,
            'bedrooms' => $this->bedrooms,
            'beds' => $this->beds,
            'sofa_beds' => $this->sofa_beds,
            'bathrooms' => $this->bathrooms,
            'currency' => $this->priceCurrency(),
            'base_price_cents' => (int) $this->effectiveAmount('base_price_cents'),
            'cleaning_fee_cents' => (int) $this->effectiveAmount('cleaning_fee_cents'),
            'deposit_cents' => (int) $this->effectiveAmount('deposit_cents'),
            'amenities' => $this->whenLoaded('amenities', fn () => $this->amenities->pluck('name')),
            'photos' => $this->getMedia('photos')->map(fn ($media) => [
                'thumb' => $media->getUrl('thumb'),
                'large' => $media->getUrl('large'),
            ]),
        ];
    }
}
