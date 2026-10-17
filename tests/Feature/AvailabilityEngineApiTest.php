<?php

namespace Tests\Feature;

use App\Models\Screen;
use Tests\TestCase;

class AvailabilityEngineApiTest extends TestCase
{
    public function test_can_check_asset_availability()
    {
        $asset = Screen::first();
        if (!$asset) {
            $this->markTestSkipped('No asset found for availability test.');
        }

        $payload = [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'items' => [
                [
                    'asset_id' => $asset->id,
                    'quantity' => 1,
                ]
            ]
        ];

        $response = $this->postJson('/api/v2/availability/check', $payload);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'available',
                     'conflicts',
                 ]);
    }
}
