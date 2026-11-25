<?php

namespace App\Enums;

enum BookingStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case New = 'new';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
            self::Completed => 'Completed',
        };
    }

    /**
     * The state machine: only these transitions are allowed.
     *
     * @return self[]
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::New => [self::Pending, self::Confirmed, self::Cancelled],
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Paid, self::Cancelled, self::NoShow, self::Completed],
            self::Paid => [self::Completed, self::NoShow, self::Cancelled],
            self::Cancelled, self::NoShow, self::Completed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Confirmed by the host (paid or not): the guest is really coming, or came. */
    public function isConfirmed(): bool
    {
        return in_array($this, [self::Confirmed, self::Paid, self::Completed], true);
    }

    /**
     * The stored values of every status that is confirmed (see isConfirmed),
     * for a query.
     *
     * @return array<int, string>
     */
    public static function confirmedValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->isConfirmed()),
        ));
    }
    public function getLabel(): string
    {
        return __($this->label());
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Pending => 'warning',
            self::Confirmed => 'primary',
            self::Paid => 'success',
            self::Cancelled => 'danger',
            self::NoShow => 'gray',
            self::Completed => 'success',
        };
    }
}
