<?php

namespace App\Models;

use App\Enums\MailType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'mail_type', 'recipient', 'status', 'error'])]
class SentMail extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected function casts(): array
    {
        return [
            'mail_type' => MailType::class,
        ];
    }
}
