<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_id', 'name', 'start_date', 'end_date', 'price_cents', 'weekend_price_cents'])]
class RateSeason extends Model
{
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
        ];
    }
}
