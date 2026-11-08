<?php

namespace App\Enums;

enum CancellationPolicy: string implements \Filament\Support\Contracts\HasLabel
{
    case Flexible = 'flexible';
    case Moderate = 'moderate';
    case Strict = 'strict';
    case Custom = 'custom';

    public function getLabel(): string
    {
        return __($this->label());
    }

    public function label(): string
    {
        return match ($this) {
            self::Flexible => 'Flexible',
            self::Moderate => 'Moderate',
            self::Strict => 'Strict',
            self::Custom => 'Custom conditions',
        };
    }

    /**
     * The conditions as separate lines. The admin can edit the texts of the
     * fixed policies (see Property::cancellationRules); if nothing is filled
     * in, the default text below applies. For "custom" the text comes from
     * the free text field on the property.
     *
     * @return string[]
     */
    public function lines(?string $ownText = null): array
    {
        if ($this === self::Custom) {
            return [];
        }

        if (filled($ownText)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $ownText))));
        }

        return $this->defaultLines();
    }

    /**
     * The built-in default texts (translated), used when no custom text
     * is filled in.
     *
     * @return string[]
     */
    public function defaultLines(): array
    {
        return match ($this) {
            self::Flexible => [
                __('Full refund up to 24 hours before check-in.'),
                __('After that, the amount paid is non-refundable.'),
            ],
            self::Moderate => [
                __('Full refund up to 5 days before check-in.'),
                __('After that, a 50% refund of the stay, excluding cleaning fees.'),
            ],
            self::Strict => [
                __('Full refund up to 14 days before check-in.'),
                __('Up to 7 days before check-in: 50% refund.'),
                __('After that, the amount paid is non-refundable.'),
            ],
            self::Custom => [],
        };
    }
}
