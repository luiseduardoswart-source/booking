<?php

namespace App\Rules;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * On the departure date: a guest books at most Booking::MAX_STAY_NIGHTS
 * nights. Every night is priced one by one, and a stay of a hundred years
 * costs more than a payment provider accepts.
 */
final class StayLength implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $arrival = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($this->data['arrival'] ?? ''));
            $departure = CarbonImmutable::createFromFormat('!Y-m-d', (string) $value);
        } catch (Throwable) {
            return; // not a date: the date rules say so
        }

        if ($arrival->diffInDays($departure) > Booking::MAX_STAY_NIGHTS) {
            $fail(__('A stay can be at most a year.'));
        }
    }
}
