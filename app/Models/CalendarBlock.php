<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Enums\CalendarBlockType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['property_id', 'type', 'start_date', 'end_date', 'source', 'external_uid', 'note'])]
class CalendarBlock extends Model
{
    use GuardedOwnership, LogsActivity;

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => CalendarBlockType::class,
            'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
        ];
    }
}
