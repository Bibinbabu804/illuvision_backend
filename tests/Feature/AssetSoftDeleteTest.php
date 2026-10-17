<?php

namespace Tests\Feature;

use App\Models\Screen;
use App\Models\User;
use App\Models\City;
use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetSoftDeleteTest extends TestCase
{
    public function test_asset_can_be_soft_deleted()
    {
        $country = Country::firstOrCreate(['name' => 'India', 'code' => 'IN']);
        $city = City::firstOrCreate(['country_id' => $country->id, 'name' => 'TestCity']);

        $screen = Screen::create([
            'name' => 'Test Screen for Soft Delete',
            'screen_code' => 'TEST-DEL-' . strtoupper(substr(uniqid(), -6)),
            'asset_type' => 'fixed',
            'screen_type' => 'Fixed Billboard',
            'location_name' => 'Test Location',
            'country_id' => $country->id,
            'city_id' => $city->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);

        $this->assertDatabaseHas('screens', [
            'id' => $screen->id,
            'deleted_at' => null,
        ]);

        // Perform soft delete
        $screen->delete();

        // Check that deleted_at is filled in database
        $this->assertSoftDeleted('screens', [
            'id' => $screen->id,
        ]);

        // Default query should not find the asset
        $this->assertNull(Screen::find($screen->id));

        // Query withTrashed should find the asset
        $this->assertNotNull(Screen::withTrashed()->find($screen->id));
    }
}
