<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'property_id', 'name', 'name_translations', 'start_date', 'end_date',
    'min_nights', 'max_nights', 'arrival_days', 'departure_days',
])]
class BookingRule extends Model
{
    use HasTranslations;

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'arrival_days' => 'array',
            'departure_days' => 'array',
        ];
    }
}
