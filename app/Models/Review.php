<?php

namespace App\Models;

use App\Models\Concerns\GuardedOwnership;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['booking_id', 'property_id', 'rating', 'comment', 'guest_name', 'status', 'host_reply', 'host_replied_at'])]
class Review extends Model
{
    use GuardedOwnership, LogsActivity;

    /**
     * What status does a new review come in with?
     *
     * By default every review waits for approval. If auto-approve is on,
     * only reviews from the configured threshold (default 4 stars) are
     * published immediately — lower ratings always wait for manual
     * review.
     */
    public static function statusForNewReview(int $rating): ReviewStatus
    {
        $automatic = Setting::get('reviews', 'auto_approve', '1') === '1';
        $threshold = (int) (Setting::get('reviews', 'auto_approve_from', '4') ?: 4);

        return $automatic && $rating >= $threshold
            ? ReviewStatus::Approved
            : ReviewStatus::Pending;
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'rating'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'host_replied_at' => 'datetime',
        ];
    }
}
