<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_id', 'date', 'price_cents', 'discount_percent', 'source'])]
class NightlyRate extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PRICELABS = 'pricelabs';

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date:Y-m-d',
        ];
    }
}
