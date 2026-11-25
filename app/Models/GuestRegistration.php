<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One traveller of a booking, as the authorities ask for it (see
 * App\Http\Controllers\GuestRegistrationController). The document number and
 * the address are encrypted and never leave the model by accident.
 */
#[Fillable([
    'booking_id', 'position', 'first_name', 'last_name', 'sex', 'birth_date', 'birth_country', 'nationality',
    'document_type', 'document_number', 'document_country', 'residence_country', 'residence_city', 'residence_address',
])]
class GuestRegistration extends Model
{
    public const SEXES = ['female' => 'Female', 'male' => 'Male', 'other' => 'Other'];

    public const DOCUMENT_TYPES = [
        'passport' => 'Passport',
        'id_card' => 'Identity card',
        'residence_permit' => 'Residence permit',
        'driving_licence' => 'Driving licence',
    ];

    /** Younger than this on arrival: no identity document needed. */
    public const DOCUMENT_FROM_AGE = 14;

    protected $hidden = ['document_number', 'residence_address'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'birth_date' => 'immutable_date:Y-m-d',
            'document_number' => 'encrypted',
            'residence_address' => 'encrypted',
        ];
    }
}
