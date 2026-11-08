<?php

namespace App\Engines\Availability;

final readonly class AvailabilityResult
{
    /**
     * @param  string[]  $reasons  Reasons why the stay is not possible (empty if available)
     */
    public function __construct(
        public bool $available,
        public array $reasons = [],
    ) {}

    public static function yes(): self
    {
        return new self(true);
    }

    public static function no(string ...$reasons): self
    {
        return new self(false, $reasons);
    }

    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'reasons' => $this->reasons,
        ];
    }
}
