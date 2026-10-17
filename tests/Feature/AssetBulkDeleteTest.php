<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\City;
use App\Models\Country;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\ScreenProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Country $country;
    protected City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
        ]);

        $this->country = Country::create(['name' => 'India', 'code' => 'IN', 'currency_code' => 'INR']);
        $this->city = City::create(['name' => 'Kochi', 'country_id' => $this->country->id]);
    }

    protected function createTestScreen(array $attributes = []): Screen
    {
        return Screen::create(array_merge([
            'name' => 'Test Screen ' . rand(100, 999),
            'screen_code' => 'TEST-' . strtoupper(substr(md5(uniqid()), 0, 6)),
            'asset_type' => 'fixed',
            'screen_type' => 'digital',
            'pricing_unit' => 'per_screen',
            'country_id' => $this->country->id,
            'city_id' => $this->city->id,
            'location_name' => 'Kochi Center',
            'owner_type' => Screen::OWNER_TYPE_ILLUVISION,
            'status' => 'active',
        ], $attributes));
    }

    /** @test */
    public function unbooked_screen_can_be_single_deleted_via_soft_delete()
    {
        $screen = $this->createTestScreen();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/screens/{$screen->id}");

        $response->assertStatus(200)
            ->assertJson(['status' => true]);

        $this->assertSoftDeleted('screens', ['id' => $screen->id]);
    }

    /** @test */
    public function screen_with_active_campaign_is_blocked_from_deletion()
    {
        $screen = $this->createTestScreen();

        $booking = Booking::create([
            'user_id' => $this->admin->id,
            'screen_id' => $screen->id,
            'booking_reference' => 'BK-ACTIVE-001',
            'title' => 'Active Campaign',
            'advertiser_name' => 'Acme Corp',
            'start_time' => now(),
            'end_time' => now()->addDays(10),
            'status' => Booking::STATUS_RUNNING,
            'final_amount' => 5000,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $screen->id,
            'unit_price' => 500,
            'quantity' => 1,
            'subtotal' => 5000,
        ]);

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/screens/{$screen->id}");

        $response->assertStatus(409)
            ->assertJson([
                'status' => false,
                'errors' => [
                    'blocked_count' => 1,
                ]
            ]);

        $this->assertDatabaseHas('screens', [
            'id' => $screen->id,
            'deleted_at' => null,
        ]);
    }

    /** @test */
    public function bulk_delete_soft_deletes_all_safe_screens()
    {
        $screen1 = $this->createTestScreen();
        $screen2 = $this->createTestScreen();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/screens/bulk-delete', [
                'screen_ids' => [$screen1->id, $screen2->id],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'deleted_count' => 2,
                    'blocked_count' => 0,
                ],
            ]);

        $this->assertSoftDeleted('screens', ['id' => $screen1->id]);
        $this->assertSoftDeleted('screens', ['id' => $screen2->id]);
    }

    /** @test */
    public function bulk_delete_returns_validation_details_when_blocked_screens_exist()
    {
        $safeScreen = $this->createTestScreen();
        $blockedScreen = $this->createTestScreen();

        $booking = Booking::create([
            'user_id' => $this->admin->id,
            'screen_id' => $blockedScreen->id,
            'booking_reference' => 'BK-SCHEDULED-001',
            'title' => 'Scheduled Campaign',
            'advertiser_name' => 'Acme Corp',
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(15),
            'status' => Booking::STATUS_SCHEDULED,
            'final_amount' => 10000,
        ]);

        BookingScreen::create([
            'booking_id' => $booking->id,
            'screen_id' => $blockedScreen->id,
            'unit_price' => 1000,
            'quantity' => 1,
            'subtotal' => 10000,
        ]);

        // Attempt bulk delete without forceSafeOnly
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/screens/bulk-delete', [
                'screen_ids' => [$safeScreen->id, $blockedScreen->id],
                'confirm_safe_only' => false,
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'status' => false,
                'errors' => [
                    'total' => 2,
                    'safe_count' => 1,
                    'blocked_count' => 1,
                ],
            ]);

        // Neither screen should be deleted yet
        $this->assertDatabaseHas('screens', ['id' => $safeScreen->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('screens', ['id' => $blockedScreen->id, 'deleted_at' => null]);

        // Now confirm safe-only deletion
        $confirmResponse = $this->actingAs($this->admin)
            ->postJson('/api/admin/screens/bulk-delete', [
                'screen_ids' => [$safeScreen->id, $blockedScreen->id],
                'confirm_safe_only' => true,
            ]);

        $confirmResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'deleted_count' => 1,
                    'blocked_count' => 1,
                ],
            ]);

        $this->assertSoftDeleted('screens', ['id' => $safeScreen->id]);
        $this->assertDatabaseHas('screens', ['id' => $blockedScreen->id, 'deleted_at' => null]);
    }

    /** @test */
    public function provider_screen_delete_soft_deletes_provider_screens_only()
    {
        $provider = ScreenProvider::create([
            'provider_name' => 'Media One Ads',
            'business_name' => 'Media One Pvt Ltd',
            'contact_person' => 'John Doe',
            'phone' => '9876543210',
            'email' => 'provider@example.com',
            'status' => ScreenProvider::STATUS_APPROVED,
        ]);

        $providerScreen1 = $this->createTestScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
        ]);
        $providerScreen2 = $this->createTestScreen([
            'owner_type' => Screen::OWNER_TYPE_PROVIDER,
            'screen_provider_id' => $provider->id,
        ]);
        $otherScreen = $this->createTestScreen();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/admin/screen-providers/{$provider->id}/delete-screens");

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'data' => [
                    'deleted_count' => 2,
                ],
            ]);

        // Provider screens soft deleted
        $this->assertSoftDeleted('screens', ['id' => $providerScreen1->id]);
        $this->assertSoftDeleted('screens', ['id' => $providerScreen2->id]);

        // Unrelated screen untouched
        $this->assertDatabaseHas('screens', ['id' => $otherScreen->id, 'deleted_at' => null]);

        // ScreenProvider entity itself remains untouched
        $this->assertDatabaseHas('screen_providers', ['id' => $provider->id]);
    }

    /** @test */
    public function unauthenticated_user_cannot_bulk_delete_screens()
    {
        $screen = $this->createTestScreen();

        $response = $this->postJson('/api/admin/screens/bulk-delete', [
            'screen_ids' => [$screen->id],
        ]);

        $response->assertStatus(401);
    }
}
