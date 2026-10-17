<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetManagementFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private State $kerala;
    private City $kochi;
    private City $kottayam;
    private ScreenCategory $mallCat;
    private ScreenCategory $theatreCat;
    private PricingPlan $planA;
    private PricingPlan $planB;
    private ScreenProvider $providerA;
    private ScreenProvider $providerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);
        $this->kottayam = City::create(['name' => 'Kottayam', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);

        $this->mallCat = ScreenCategory::firstOrCreate(['slug' => 'mall-display'], ['name' => 'Mall Display', 'status' => 'active']);
        $this->theatreCat = ScreenCategory::firstOrCreate(['slug' => 'theatre-screen'], ['name' => 'Theatre Screen', 'status' => 'active']);

        $this->planA = PricingPlan::create([
            'name' => 'Starter Plan', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day', 'is_active' => true,
        ]);
        $this->planB = PricingPlan::create([
            'name' => 'Growth Plan', 'base_price' => 1500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day', 'is_active' => true,
        ]);

        $this->providerA = ScreenProvider::create([
            'provider_name' => 'Alpha Media',
            'business_name' => 'Alpha Media Ltd',
            'email' => 'alpha@test.com',
            'phone' => '9876543210',
            'status' => 'approved',
        ]);
        $this->providerB = ScreenProvider::create([
            'provider_name' => 'Beta Displays',
            'business_name' => 'Beta Displays Pvt Ltd',
            'email' => 'beta@test.com',
            'phone' => '9876543211',
            'status' => 'approved',
        ]);
    }

    private function createScreen(array $attributes = []): Screen
    {
        static $counter = 1;
        $idx = $counter++;

        return Screen::create(array_merge([
            'name' => "Screen {$idx}",
            'screen_code' => "SCR-{$idx}-" . strtoupper(uniqid()),
            'asset_type' => 'fixed',
            'screen_type' => 'LED Screen',
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => 'Downtown ' . $idx,
            'screen_category_id' => $this->mallCat->id,
            'pricing_plan_id' => $this->planA->id,
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'status' => 'active',
            'booking_status' => 'available',
        ], $attributes));
    }

    /** 1. All assets default listing */
    public function test_all_assets_default_listing(): void
    {
        $this->createScreen(['name' => 'Asset 1']);
        $this->createScreen(['name' => 'Asset 2']);
        $this->createScreen(['name' => 'Asset 3', 'screen_category_id' => null, 'pricing_plan_id' => null]);

        $response = $this->getJson('/api/screens?perPage=10');

        $response->assertStatus(200)->assertJsonPath('status', true);
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('meta.total'));
    }

    /** 2. Assets without category (screen_category_id IS NULL) */
    public function test_assets_without_category(): void
    {
        $withCat = $this->createScreen(['name' => 'With Cat', 'screen_category_id' => $this->mallCat->id]);
        $withoutCat = $this->createScreen(['name' => 'Without Cat', 'screen_category_id' => null]);

        $response = $this->getJson('/api/screens?category_filter=without');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($withoutCat->id, $data[0]['id']);
        $this->assertNull($data[0]['screen_category_id']);
        $this->assertNull($data[0]['screen_category']);

        // Also test screen_category_id=without parameter format
        $response2 = $this->getJson('/api/screens?screen_category_id=without');
        $response2->assertStatus(200);
        $this->assertCount(1, $response2->json('data'));
        $this->assertEquals($withoutCat->id, $response2->json('data.0.id'));
    }

    /** 3. Assets with category (screen_category_id IS NOT NULL) */
    public function test_assets_with_category(): void
    {
        $withCat1 = $this->createScreen(['name' => 'With Mall', 'screen_category_id' => $this->mallCat->id]);
        $withCat2 = $this->createScreen(['name' => 'With Theatre', 'screen_category_id' => $this->theatreCat->id]);
        $withoutCat = $this->createScreen(['name' => 'Without Cat', 'screen_category_id' => null]);

        $response = $this->getJson('/api/screens?category_filter=with');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertContains($withCat1->id, $ids);
        $this->assertContains($withCat2->id, $ids);
        $this->assertNotContains($withoutCat->id, $ids);
    }

    /** 4. Specific screen category ID */
    public function test_assets_by_specific_category(): void
    {
        $mallScreen = $this->createScreen(['name' => 'Mall Screen', 'screen_category_id' => $this->mallCat->id]);
        $theatreScreen = $this->createScreen(['name' => 'Theatre Screen', 'screen_category_id' => $this->theatreCat->id]);

        $response = $this->getJson('/api/screens?screen_category_id=' . $this->theatreCat->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($theatreScreen->id, $data[0]['id']);
    }

    /** 5. Assets without pricing plan (pricing_plan_id IS NULL) */
    public function test_assets_without_pricing_plan(): void
    {
        $withPlan = $this->createScreen(['name' => 'With Plan', 'pricing_plan_id' => $this->planA->id]);
        $withoutPlan = $this->createScreen(['name' => 'Without Plan', 'pricing_plan_id' => null]);

        $response = $this->getJson('/api/screens?pricing_plan_filter=without');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($withoutPlan->id, $data[0]['id']);
        $this->assertNull($data[0]['pricing_plan_id']);

        // Also test pricing_plan_id=without parameter format
        $response2 = $this->getJson('/api/screens?pricing_plan_id=without');
        $response2->assertStatus(200);
        $this->assertCount(1, $response2->json('data'));
        $this->assertEquals($withoutPlan->id, $response2->json('data.0.id'));
    }

    /** 6. Assets with pricing plan (pricing_plan_id IS NOT NULL) */
    public function test_assets_with_pricing_plan(): void
    {
        $withPlan1 = $this->createScreen(['name' => 'Plan A', 'pricing_plan_id' => $this->planA->id]);
        $withPlan2 = $this->createScreen(['name' => 'Plan B', 'pricing_plan_id' => $this->planB->id]);
        $withoutPlan = $this->createScreen(['name' => 'No Plan', 'pricing_plan_id' => null]);

        $response = $this->getJson('/api/screens?pricing_plan_filter=with');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertContains($withPlan1->id, $ids);
        $this->assertContains($withPlan2->id, $ids);
        $this->assertNotContains($withoutPlan->id, $ids);
    }

    /** 7. Specific pricing plan ID */
    public function test_assets_by_specific_pricing_plan(): void
    {
        $screenA = $this->createScreen(['name' => 'Plan A Screen', 'pricing_plan_id' => $this->planA->id]);
        $screenB = $this->createScreen(['name' => 'Plan B Screen', 'pricing_plan_id' => $this->planB->id]);

        $response = $this->getJson('/api/screens?pricing_plan_id=' . $this->planB->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($screenB->id, $data[0]['id']);
    }

    /** 8. Combined filters: Without Category + Without Pricing Plan + Fixed + Provider + City */
    public function test_combined_filters(): void
    {
        // Target match
        $target = $this->createScreen([
            'name' => 'Target Screen',
            'screen_category_id' => null,
            'pricing_plan_id' => null,
            'asset_type' => 'fixed',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'city_id' => $this->kochi->id,
        ]);

        // Non-matching screens (differ in 1 dimension)
        $diffCat = $this->createScreen([
            'name' => 'Diff Cat',
            'screen_category_id' => $this->mallCat->id,
            'pricing_plan_id' => null,
            'asset_type' => 'fixed',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'city_id' => $this->kochi->id,
        ]);

        $diffPlan = $this->createScreen([
            'name' => 'Diff Plan',
            'screen_category_id' => null,
            'pricing_plan_id' => $this->planA->id,
            'asset_type' => 'fixed',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'city_id' => $this->kochi->id,
        ]);

        $diffProvider = $this->createScreen([
            'name' => 'Diff Provider',
            'screen_category_id' => null,
            'pricing_plan_id' => null,
            'asset_type' => 'fixed',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerB->id,
            'city_id' => $this->kochi->id,
        ]);

        $diffCity = $this->createScreen([
            'name' => 'Diff City',
            'screen_category_id' => null,
            'pricing_plan_id' => null,
            'asset_type' => 'fixed',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'city_id' => $this->kottayam->id,
        ]);

        $response = $this->getJson('/api/screens?' . http_build_query([
            'category_filter' => 'without',
            'pricing_plan_filter' => 'without',
            'asset_type' => 'fixed',
            'screen_provider_id' => $this->providerA->id,
            'city_id' => $this->kochi->id,
        ]));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($target->id, $data[0]['id']);
    }

    /** 9. Provider + Category */
    public function test_provider_and_category_filter(): void
    {
        $match = $this->createScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'screen_category_id' => $this->mallCat->id,
        ]);
        $other = $this->createScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerB->id,
            'screen_category_id' => $this->mallCat->id,
        ]);

        $response = $this->getJson('/api/screens?' . http_build_query([
            'screen_provider_id' => $this->providerA->id,
            'screen_category_id' => $this->mallCat->id,
        ]));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($match->id, $data[0]['id']);
    }

    /** 10. Provider + Pricing Plan */
    public function test_provider_and_pricing_plan_filter(): void
    {
        $match = $this->createScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => $this->planB->id,
        ]);
        $noPlan = $this->createScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_id' => null,
        ]);

        $response = $this->getJson('/api/screens?' . http_build_query([
            'screen_provider_id' => $this->providerA->id,
            'pricing_plan_filter' => 'without',
        ]));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($noPlan->id, $data[0]['id']);
    }

    /** 11. Search + Category / Pricing Plan */
    public function test_search_with_category_and_pricing_plan(): void
    {
        $match = $this->createScreen([
            'name' => 'Central Hub Prime',
            'screen_category_id' => null,
            'pricing_plan_id' => $this->planA->id,
        ]);
        $diffSearch = $this->createScreen([
            'name' => 'Airport Billboard',
            'screen_category_id' => null,
            'pricing_plan_id' => $this->planA->id,
        ]);

        $response = $this->getJson('/api/screens?' . http_build_query([
            'search' => 'Central Hub',
            'category_filter' => 'without',
            'pricing_plan_id' => $this->planA->id,
        ]));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($match->id, $data[0]['id']);
    }

    /** 12. Server-side Pagination with Filters */
    public function test_server_side_pagination_with_filters(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->createScreen([
                'name' => "Unassigned Asset {$i}",
                'screen_category_id' => null,
                'pricing_plan_id' => $this->planA->id,
            ]);
        }

        // Page 1 with per_page=10
        $response1 = $this->getJson('/api/screens?category_filter=without&page=1&per_page=10');
        $response1->assertStatus(200);
        $this->assertCount(10, $response1->json('data'));
        $this->assertEquals(30, $response1->json('meta.total'));
        $this->assertEquals(1, $response1->json('meta.current_page'));
        $this->assertEquals(3, $response1->json('meta.last_page'));

        // Page 2
        $response2 = $this->getJson('/api/screens?category_filter=without&page=2&per_page=10');
        $response2->assertStatus(200);
        $this->assertCount(10, $response2->json('data'));
        $this->assertEquals(2, $response2->json('meta.current_page'));

        // Page 1 and Page 2 contain distinct records
        $ids1 = array_column($response1->json('data'), 'id');
        $ids2 = array_column($response2->json('data'), 'id');
        $this->assertEmpty(array_intersect($ids1, $ids2));
    }

    /** 13. Accurate KPI stats reflecting active filter */
    public function test_accurate_kpi_stats_reflecting_active_filter(): void
    {
        $this->createScreen(['screen_category_id' => $this->mallCat->id, 'asset_type' => 'fixed', 'fleet_size' => 1]);
        $this->createScreen(['screen_category_id' => $this->mallCat->id, 'asset_type' => 'fixed', 'fleet_size' => 1]);
        $this->createScreen(['screen_category_id' => null, 'asset_type' => 'fixed', 'fleet_size' => 1]);

        $response = $this->getJson('/api/screens?category_filter=without');
        $response->assertStatus(200);

        $stats = $response->json('meta.stats');
        $this->assertEquals(1, $stats['total']);
        $this->assertEquals(1, $stats['fixed_count']);
        $this->assertEquals(0, $stats['mobile_count']);
    }

    /** 14. Empty filtered result */
    public function test_empty_filtered_result(): void
    {
        $this->createScreen(['screen_category_id' => $this->mallCat->id]);

        $response = $this->getJson('/api/screens?category_filter=without');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
        $this->assertEquals(0, $response->json('meta.total'));
    }

    /** 15. Soft deleted asset is excluded */
    public function test_soft_deleted_asset_is_excluded(): void
    {
        $screen = $this->createScreen(['screen_category_id' => null]);
        $screen->delete();

        $response = $this->getJson('/api/screens?category_filter=without');
        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    /** 16. Dynamic update of category changes filter membership */
    public function test_updating_category_changes_filter_membership(): void
    {
        $screen = $this->createScreen(['screen_category_id' => null]);

        // Initially in "without"
        $res1 = $this->getJson('/api/screens?category_filter=without');
        $this->assertCount(1, $res1->json('data'));

        // Assign category
        $screen->update(['screen_category_id' => $this->mallCat->id]);

        // Now absent from "without" and present in "with"
        $res2 = $this->getJson('/api/screens?category_filter=without');
        $this->assertCount(0, $res2->json('data'));

        $res3 = $this->getJson('/api/screens?category_filter=with');
        $this->assertCount(1, $res3->json('data'));
    }

    /** 17. Dynamic update of pricing plan changes filter membership */
    public function test_updating_pricing_plan_changes_filter_membership(): void
    {
        $screen = $this->createScreen(['pricing_plan_id' => null]);

        // Initially in "without"
        $res1 = $this->getJson('/api/screens?pricing_plan_filter=without');
        $this->assertCount(1, $res1->json('data'));

        // Assign pricing plan
        $screen->update(['pricing_plan_id' => $this->planA->id]);

        // Now absent from "without" and present in "with"
        $res2 = $this->getJson('/api/screens?pricing_plan_filter=without');
        $this->assertCount(0, $res2->json('data'));

        $res3 = $this->getJson('/api/screens?pricing_plan_filter=with');
        $this->assertCount(1, $res3->json('data'));
    }
}
