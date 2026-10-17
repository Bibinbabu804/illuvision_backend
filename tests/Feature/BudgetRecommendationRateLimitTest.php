<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * BP6 P0-2 — Recommendation endpoint rate limiting & request sanity limits.
 *
 * POST /recommendations/calculate is a public, computationally expensive
 * endpoint (full inventory × pricing-oracle sweep). It is throttled per
 * client IP and capped by request-size sanity limits so abusive requests
 * cannot trigger unbounded solver work while normal planner usage is
 * unaffected.
 */
class BudgetRecommendationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;
    private State $kerala;
    private City $kochi;
    private ScreenCategory $mall;
    private PricingPlan $planA;
    private Screen $fixedKochiMall;

    private string $startDate = '2026-11-01';
    private string $endDate = '2026-11-30';

    protected function setUp(): void
    {
        parent::setUp();

        // Use a small, fast limit for the test (config is read per-request by
        // the limiter closure).
        config(['app.recommendations_rate_per_minute' => 5]);
        config(['app.recommendations_rate_per_hour' => 1000]);
        RateLimiter::clear('recommendations');

        AdvertisementCategory::create([
            'name' => 'General', 'slug' => 'general', 'status' => 'active',
        ]);

        $this->country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->country->id]);
        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->country->id]);

        $this->mall = ScreenCategory::create(['name' => 'Mall', 'slug' => 'mall', 'status' => 'active']);

        $this->planA = PricingPlan::create([
            'name' => 'Standard Daily', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => true, 'is_active' => true,
        ]);

        $this->fixedKochiMall = Screen::create([
            'name' => 'Kochi Mall Screen',
            'screen_code' => 'FIX-RL-001',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => $this->mall->id,
            'country_id' => $this->country->id,
            'city_id' => $this->kochi->id,
            'location_name' => $this->kochi->name,
            'pricing_plan_id' => $this->planA->id,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'budget' => 100000,
            'country_id' => $this->country->id,
            'state_ids' => [$this->kerala->id],
            'city_ids' => [$this->kochi->id],
            'screen_category_ids' => [],
            'campaign_preference' => 'more_screens',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ], $overrides);
    }

    // ─── Rate limiting ──────────────────────────────────────────────

    public function test_requests_within_limit_succeed(): void
    {
        // Limit is 5/min in the test config → 5 valid requests must all pass.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/calculate', $this->payload())
                ->assertStatus(200);
        }
    }

    public function test_request_exceeding_limit_returns_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/calculate', $this->payload())
                ->assertStatus(200);
        }

        // 6th request within the same minute → throttled.
        $this->postJson('/api/recommendations/calculate', $this->payload())
            ->assertStatus(429);
    }

    public function test_valid_request_recovers_after_limit_reset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/recommendations/calculate', $this->payload())
                ->assertStatus(200);
        }
        $this->postJson('/api/recommendations/calculate', $this->payload())
            ->assertStatus(429);

        // Simulate the minute window elapsing (rate-limiter counters reset) —
        // normal planner usage must work again. The array cache store holds
        // the throttle attempt counters, so flushing it resets the window.
        \Illuminate\Support\Facades\Cache::flush();
        RateLimiter::clear('recommendations');

        $this->postJson('/api/recommendations/calculate', $this->payload())
            ->assertStatus(200);
    }

    // ─── Request sanity limits ──────────────────────────────────────

    public function test_oversized_payload_rejected_without_solving(): void
    {
        // 51 states > max:50 — must be rejected by validation (422), before
        // any solver work happens.
        $tooManyStates = range(1, 51);

        $this->postJson('/api/recommendations/calculate', $this->payload([
            'state_ids' => $tooManyStates,
        ]))->assertStatus(422);
    }

    public function test_oversized_budget_rejected(): void
    {
        $this->postJson('/api/recommendations/calculate', $this->payload([
            'budget' => 100000001, // > max:100000000
        ]))->assertStatus(422);
    }

    public function test_oversized_category_list_rejected(): void
    {
        $this->postJson('/api/recommendations/calculate', $this->payload([
            'screen_category_ids' => range(1, 31), // > max:30
        ]))->assertStatus(422);
    }

    public function test_overlong_campaign_window_rejected(): void
    {
        // Window > 180 days → rejected.
        $this->postJson('/api/recommendations/calculate', $this->payload([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]))->assertStatus(422);
    }

    public function test_valid_payload_with_all_limits_still_succeeds(): void
    {
        // A legitimate large campaign (30 states, 150 cities, 20 categories,
        // large budget, 180-day window) must still be accepted and solved.
        $stateIds = [];
        for ($i = 0; $i < 30; $i++) {
            $stateIds[] = State::create([
                'name' => 'State ' . $i, 'country_id' => $this->country->id,
            ])->id;
        }
        $cityIds = [];
        for ($i = 0; $i < 30; $i++) {
            $cityIds[] = City::create([
                'name' => 'City ' . $i, 'state_id' => $stateIds[$i % 30],
                'country_id' => $this->country->id,
            ])->id;
        }
        $categoryIds = [];
        for ($i = 0; $i < 20; $i++) {
            $categoryIds[] = ScreenCategory::create([
                'name' => 'Category ' . $i, 'slug' => 'category-' . $i, 'status' => 'active',
            ])->id;
        }

        $this->postJson('/api/recommendations/calculate', $this->payload([
            'budget' => 90000000,
            'state_ids' => $stateIds,
            'city_ids' => $cityIds,
            'screen_category_ids' => $categoryIds,
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-29',
        ]))->assertStatus(200);
    }
}
