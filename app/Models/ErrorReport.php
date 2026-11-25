<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One kind of error the website ran into (see App\Support\ErrorReports). */
#[Fillable(['fingerprint', 'level', 'message', 'location', 'occurrences', 'first_seen_at', 'last_seen_at'])]
class ErrorReport extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
