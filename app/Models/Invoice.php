<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['booking_id', 'invoice_number', 'issued_at', 'total_cents', 'vat_cents', 'vat_percentage'])]
class Invoice extends Model
{
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_date:Y-m-d',
        ];
    }
}
