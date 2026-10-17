<?php

namespace Tests\Unit;

use App\Models\Screen;
use App\Enums\AssetType;
use App\Services\InventoryService;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    public function test_available_fleet_returns_total_fleet_size_when_no_bookings_exist()
    {
        $asset = Screen::where('asset_type', AssetType::MOBILE_VAN->value)->first();
        if (!$asset) {
            $this->markTestSkipped('No mobile asset found.');
        }

        $service = new InventoryService();
        $avail = $service->getAvailableFleet($asset->id, '2026-09-01', '2026-09-05');

        $this->assertEquals($asset->fleet_size, $avail);
    }
}
