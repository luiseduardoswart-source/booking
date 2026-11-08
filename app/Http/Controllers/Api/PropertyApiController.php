<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyApiResource;
use App\Models\Property;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyApiController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PropertyApiResource::collection(
            Property::query()
                ->where('active', true)
                ->with(['media', 'amenities'])
                ->orderBy('name')
                ->get(),
        );
    }

    public function show(Property $property): PropertyApiResource
    {
        abort_unless($property->active, 404);

        return new PropertyApiResource($property->load(['media', 'amenities']));
    }
}
