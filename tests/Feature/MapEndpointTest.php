<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Screen;
use App\Models\Country;
use App\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MapEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_endpoint_returns_active_unpaginated_inventory(): void
    {
        $response = $this->getJson('/api/screens/map');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'screen_code',
                        'asset_code',
                        'asset_type',
                        'pricing_unit',
                        'fleet_size',
                        'working_hours_pd',
                        'travel_distance_km_pd',
                        'is_mobile',
                        'screen_type',
                        'screen_category_id',
                        'country_id',
                        'state_id',
                        'city_id',
                        'location_name',
                        'pricing_plan_id',
                        'status',
                        'booking_status',
                        'already_reserved',
                        'orientation',
                        'latitude',
                        'longitude',
                        'thumbnail',
                        'city',
                        'pricing_plan',
                        'coverage_cities',
                        'coverage_states',
                    ],
                ],
                'meta' => [
                    'total',
                ],
            ]);

        $json = $response->json();
        $this->assertTrue($json['status']);
        $this->assertIsArray($json['data']);
        $this->assertEquals($json['meta']['total'], count($json['data']));
    }

    public function test_existing_screens_endpoint_remains_paginated_and_unchanged(): void
    {
        $response = $this->getJson('/api/screens?perPage=10');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data',
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'stats',
                ],
            ]);

        $this->assertIsArray($response->json('data'));
    }
}
