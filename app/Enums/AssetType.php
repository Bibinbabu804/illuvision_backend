<?php

namespace App\Enums;

enum AssetType: string
{
    case FIXED = 'fixed';
    case MOBILE = 'mobile';
    case MOBILE_VAN = 'mobile_van';
    case MOBILE_TRUCK = 'mobile_truck';
    case BACKPACK_TEAM = 'backpack_team';
    case WALKING_PROMO = 'walking_promo';
    case EVENT_DISPLAY = 'event_display';

    public function label(): string
    {
        return match ($this) {
            self::FIXED => 'Fixed Digital Screen',
            self::MOBILE => 'Mobile Asset',
            self::MOBILE_VAN => 'LED Advertising Van',
            self::MOBILE_TRUCK => 'LED Advertising Truck',
            self::BACKPACK_TEAM => 'Backpack Advertising Team',
            self::WALKING_PROMO => 'Walking Promotion Team',
            self::EVENT_DISPLAY => 'Event / Pop-up Display',
        };
    }

    public function isMobile(): bool
    {
        return $this !== self::FIXED;
    }
}
