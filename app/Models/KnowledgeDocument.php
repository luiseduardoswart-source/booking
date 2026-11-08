<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document from the knowledge base: extra knowledge for the AI chat on the site.
 * Without an accommodation it applies to all accommodations.
 */
#[Fillable(['property_id', 'name', 'content', 'active'])]
class KnowledgeDocument extends Model
{
    /** Anything longer is truncated: otherwise a document fills the whole context of the chat. */
    public const MAX_CHARACTERS = 60000;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The active documents the chat may read: on an accommodation's page the
     * general ones and that accommodation's, elsewhere the general ones
     * and those of all accommodations that are on the site.
     */
    public function scopeForChat(Builder $query, ?Property $accommodation): void
    {
        $query->where('active', true)->where(fn (Builder $q) => $q
            ->whereNull('property_id')
            ->when(
                $accommodation,
                fn (Builder $q) => $q->orWhere('property_id', $accommodation->id),
                fn (Builder $q) => $q->orWhereHas('property', fn (Builder $p) => $p->where('active', true)),
            ));
    }
}
