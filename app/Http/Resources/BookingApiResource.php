<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'property_id' => $this->property_id,
            'status' => $this->status->value,
            'payment_status' => $this->payment_status->value,
            'guest_name' => $this->guest_name,
            'guest_email' => $this->guest_email,
            'arrival_date' => $this->arrival_date->toDateString(),
            'departure_date' => $this->departure_date->toDateString(),
            'guests' => $this->guests,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'down_payment_cents' => $this->down_payment_cents,
            'deposit_cents' => $this->deposit_cents,
            'price_snapshot' => $this->price_snapshot,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
