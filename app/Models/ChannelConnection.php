<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['property_id', 'provider', 'name', 'url', 'active', 'last_synced_at', 'last_error'])]
class ChannelConnection extends Model
{
    use GuardedOwnership;

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Unique source name for the CalendarEngine, so each connection manages its
     * own set of external blocks.
     */
    public function sourceKey(): string
    {
        return "{$this->provider}-{$this->id}";
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
