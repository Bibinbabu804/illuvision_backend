<?php

namespace Tests\Feature;

use App\Models\Screen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingEngineApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_can_calculate_multi_asset_pricing()
    {
        $asset = Screen::first();
        if (!$asset) {
            $this->markTestSkipped('No asset found for pricing test.');
        }

        $payload = [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-10',
            'items' => [
                [
                    'asset_id' => $asset->id,
                    'quantity' => 3,
                ]
            ]
        ];

        $response = $this->postJson('/api/v2/pricing/calculate', $payload);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         'summary',
                         'line_items',
                         'pricing' => [
                             'gross_total',
                             'duration_discount',
                             'final_total',
                         ]
                     ]
                 ]);
    }
}
