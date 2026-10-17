<?php

namespace App\Domain\Pricing\Enums;

enum PricingUnit: string
{
    case PER_SCREEN = 'per_screen';
    case PER_VEHICLE = 'per_vehicle';
    case PER_TEAM = 'per_team';
    case PER_EVENT = 'per_event';

    public function label(): string
    {
        return match ($this) {
            self::PER_SCREEN => 'Per Screen / Day',
            self::PER_VEHICLE => 'Per Vehicle / Day',
            self::PER_TEAM => 'Per Team / Day',
            self::PER_EVENT => 'Per Event / Day',
        };
    }
}
