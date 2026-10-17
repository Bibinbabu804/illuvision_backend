<?php

namespace Tests\Feature;

use App\Models\Screen;
use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEC-02 security-contract change.
     *
     * GET /api/v2/assets was registered with NO auth middleware, so this test passed
     * anonymously. It now requires `screens.view`. The route's RESPONSE CONTRACT is
     * deliberately unchanged - only the caller identity is. Every business assertion
     * below is byte-for-byte the original.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function asAdmin(): self
    {
        $this->actingAs(User::create([
            'name' => 'Asset Admin',
            'email' => 'asset-api-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]));

        return $this;
    }

    public function test_can_list_assets_via_v2_api()
    {
        $response = $this->asAdmin()->getJson('/api/v2/assets');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         '*' => [
                             'id',
                             'name',
                             'asset_type',
                             'pricing_unit',
                             'fleet_size',
                         ]
                     ]
                 ]);
    }

    public function test_can_filter_assets_by_asset_type()
    {
        $response = $this->asAdmin()->getJson('/api/v2/assets?asset_type=' . AssetType::MOBILE_VAN->value);

        $response->assertStatus(200);
    }
}
