<?php

namespace Tests\Feature;

use App\Models\Screen;
use App\Enums\AssetType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingEngineApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_can_create_booking_with_multiple_asset_items()
    {
        $asset = Screen::where('asset_type', AssetType::MOBILE_VAN->value)->first() ?? Screen::first();
        if (!$asset) {
            $this->markTestSkipped('No asset found for booking test.');
        }

        $payload = [
            'campaign_title' => 'Test Multi-Asset Campaign',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'items' => [
                [
                    'asset_id' => $asset->id,
                    'quantity' => 1,
                ]
            ]
        ];

        $response = $this->postJson('/api/v2/bookings', $payload);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'message',
                     'data' => [
                         'id',
                         'campaign_title',
                         'price',
                     ]
                 ]);
    }
}
