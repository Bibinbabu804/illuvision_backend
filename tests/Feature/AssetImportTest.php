<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\PricingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

use Tests\TestCase;

class AssetImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected ScreenProvider $provider;
    protected PricingPlan $plan;
    protected Country $country;
    protected State $state;
    protected City $city;
    protected ScreenCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India', 'is_active' => true]);
        $this->state = State::firstOrCreate(['country_id' => $this->country->id, 'name' => 'Kerala'], ['code' => 'KL', 'is_active' => true]);
        $this->city = City::firstOrCreate(['country_id' => $this->country->id, 'state_id' => $this->state->id, 'name' => 'Kochi'], ['is_active' => true]);

        $this->category = ScreenCategory::firstOrCreate(['name' => 'Restaurant Display'], ['slug' => 'restaurant-display']);

        $this->provider = ScreenProvider::create([
            'provider_name' => 'ABC Media Network',
            'business_name' => 'ABC Media Network LLC',
            'contact_person' => 'John Doe',
            'email' => 'abc@media.com',
            'phone' => '9876543210',
            'status' => 'approved',
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
        ]);

        $this->plan = PricingPlan::create([
            'name' => 'Standard Monthly Plan',
            'price_per_unit' => 50.00,
            'unit_type' => 'screen',
            'duration_days' => 30,
            'status' => 'active',
        ]);
    }

    public function test_can_download_import_template()
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/assets/import/template');
        $response->assertStatus(200);
    }

    public function test_header_mapping_and_validation_preview()
    {
        $csvContent = implode("\n", [
            'City,Asset Name,State,Illuvision Share (%),Country,Screen Category,Location Name,Provider Share (%)',
            'Kochi,"Lulu Mall Screen",Kerala,20.00,India,"Restaurant Display","Level 2 Food Court",80.00',
            'Kochi,"Oberon Cinema Screen",Kerala,30.00,India,"Restaurant Display","Lobby Zone",70.00',
        ]);

        $file = UploadedFile::fake()->createWithContent('import.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/validate', [
            'file' => $file,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total_rows', 2)
            ->assertJsonPath('summary.valid_rows', 2);
    }

    public function test_validation_fails_on_missing_required_headers()
    {
        $csvContent = implode("\n", [
            'Asset Name,Country,City',
            '"Lulu Mall Screen",India,Kochi',
        ]);

        $file = UploadedFile::fake()->createWithContent('import.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/validate', [
            'file' => $file,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_transactional_confirmation_imports_fixed_assets()
    {
        $rows = [
            [
                'validated_payload' => [
                    'name' => 'Lulu Mall Food Court Screen A',
                    'screen_code' => 'LULU-FC-101',
                    'screen_category_id' => $this->category->id,
                    'screen_type' => 'Restaurant Display',
                    'asset_type' => 'fixed',
                    'pricing_unit' => 'per_screen',
                    'fleet_size' => 1,
                    'country_id' => $this->country->id,
                    'state_id' => $this->state->id,
                    'city_id' => $this->city->id,
                    'location_name' => 'Level 2 Food Court',
                    'latitude' => 10.0261,
                    'longitude' => 76.3125,
                    'illuvision_percentage' => 20.00,
                    'screen_provider_percentage' => 80.00,
                    'status' => 'active',
                    'orientation' => 'landscape',
                    'resolution' => '1920x1080',
                    'operating_hours' => '09:00 - 23:00',
                ],
            ],
            [
                'validated_payload' => [
                    'name' => 'Lulu Mall Food Court Screen B',
                    'screen_code' => 'LULU-FC-102',
                    'screen_category_id' => $this->category->id,
                    'screen_type' => 'Restaurant Display',
                    'asset_type' => 'fixed',
                    'pricing_unit' => 'per_screen',
                    'fleet_size' => 1,
                    'country_id' => $this->country->id,
                    'state_id' => $this->state->id,
                    'city_id' => $this->city->id,
                    'location_name' => 'Level 2 Food Court East',
                    'latitude' => 10.0262,
                    'longitude' => 76.3126,
                    'illuvision_percentage' => 15.00,
                    'screen_provider_percentage' => 85.00,
                    'status' => 'active',
                    'orientation' => 'landscape',
                    'resolution' => '1920x1080',
                    'operating_hours' => '09:00 - 23:00',
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/confirm', [
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'rows' => $rows,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('imported_count', 2);

        $this->assertDatabaseHas('screens', [
            'screen_code' => 'LULU-FC-101',
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'asset_type' => 'fixed',
        ]);

        $this->assertDatabaseHas('screens', [
            'screen_code' => 'LULU-FC-102',
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'illuvision_percentage' => 15.00,
            'screen_provider_percentage' => 85.00,
            'asset_type' => 'fixed',
        ]);
    }

    public function test_auto_creates_missing_state_and_city_when_option_enabled()
    {
        $csvContent = implode("\n", [
            'City,Asset Name,State,Illuvision Share (%),Country,Screen Category,Location Name,Provider Share (%)',
            'Korba,"Niharika Chitra Mandir Screen 1",Chhattisgarh,20.00,India,"Restaurant Display","Main Road",80.00',
        ]);

        $file = UploadedFile::fake()->createWithContent('import_chhattisgarh.csv', $csvContent);

        $valResponse = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/validate', [
            'file' => $file,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'auto_create_locations' => true,
        ]);

        $valResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.valid_rows', 1);

        $rows = $valResponse->json('rows');

        $confirmResponse = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/confirm', [
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'auto_create_locations' => true,
            'rows' => $rows,
        ]);

        $confirmResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('imported_count', 1);

        $this->assertDatabaseHas('states', [
            'country_id' => $this->country->id,
            'name' => 'Chhattisgarh',
        ]);

        $this->assertDatabaseHas('cities', [
            'country_id' => $this->country->id,
            'name' => 'Korba',
        ]);

        $this->assertDatabaseHas('screens', [
            'name' => 'Niharika Chitra Mandir Screen 1',
            'location_name' => 'Main Road',
        ]);
    }

    public function test_restricts_duplicate_screen_import_when_option_enabled()
    {
        Screen::create([
            'name' => 'Existing Screen',
            'screen_code' => 'DUP-CODE-01',
            'screen_type' => 'Restaurant Display',
            'screen_category_id' => $this->category->id,
            'asset_type' => 'fixed',
            'pricing_unit' => 'per_screen',
            'fleet_size' => 1,
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'location_name' => 'Level 2 Food Court',
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'illuvision_percentage' => 20.00,
            'screen_provider_percentage' => 80.00,
            'status' => 'active',
        ]);

        $csvContent = implode("\n", [
            'City,Asset Name,State,Illuvision Share (%),Country,Screen Category,Location Name,Provider Share (%),Screen Code',
            'Kochi,"Existing Screen",Kerala,20.00,India,"Restaurant Display","Level 2 Food Court",80.00,DUP-CODE-01',
        ]);

        $file = UploadedFile::fake()->createWithContent('duplicate.csv', $csvContent);

        $response = $this->actingAs($this->admin)->postJson('/api/admin/assets/import/validate', [
            'file' => $file,
            'screen_provider_id' => $this->provider->id,
            'pricing_plan_id' => $this->plan->id,
            'restrict_duplicates' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.valid_rows', 0)
            ->assertJsonPath('summary.invalid_rows', 1);

        $rows = $response->json('rows');
        $this->assertFalse($rows[0]['is_valid']);
        $this->assertTrue($rows[0]['is_duplicate']);
    }

    public function test_returns_overall_stats_in_screens_list_meta()
    {
        Screen::create([
            'name' => 'Screen 1',
            'screen_code' => 'SCR-001',
            'screen_type' => 'Restaurant Display',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'location_name' => 'Location 1',
            'fleet_size' => 1,
            'status' => 'active',
        ]);

        Screen::create([
            'name' => 'Screen 2',
            'screen_code' => 'SCR-002',
            'screen_type' => 'Restaurant Display',
            'asset_type' => 'fixed',
            'country_id' => $this->country->id,
            'state_id' => $this->state->id,
            'city_id' => $this->city->id,
            'location_name' => 'Location 2',
            'fleet_size' => 1,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/screens');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('meta.stats.total', 2)
            ->assertJsonPath('meta.stats.fixed_count', 2)
            ->assertJsonPath('meta.stats.mobile_count', 0)
            ->assertJsonPath('meta.stats.total_units', 2);
    }
}
