<?php

namespace Tests\Feature;

use App\Models\AdvertisementCategory;
use App\Models\City;
use App\Models\Country;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenCategory;
use App\Models\State;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * BP6 P0-1 — Atomic booking availability.
 *
 * Proves that inventory (screen / mobile fleet rows) cannot be
 * over-committed by concurrent bookings:
 *
 *   1. The booking transaction takes an exclusive row lock
 *      (InventoryService::lockScreensForBooking → SELECT ... FOR UPDATE)
 *      BEFORE any availability/capacity read, so a second concurrent
 *      booking blocks until the first commits and then observes the
 *      committed booking rows.
 *   2. A deterministic raw-PDO test demonstrates the lock blocks a second
 *      transaction while the first is in-flight and is released on commit.
 *   3. A true race test forks a second process (fresh Laravel app, fresh DB
 *      connection) and fires two real POST /api/bookings/campaign requests
 *      at the same fleet with exactly one available unit: exactly one
 *      request succeeds and one is rejected with an availability error.
 *
 * NOTE: this class intentionally does NOT use RefreshDatabase. A second
 * process/connection cannot observe rows locked inside RefreshDatabase's
 * outer transaction, so fixtures are committed normally and removed in
 * tearDown().
 */
class BookingConcurrencyTest extends TestCase
{
    private array $createdIds = [
        'bookings'     => [],
        'screens'      => [],
        'cities'       => [],
        'states'       => [],
        'countries'    => [],
        'categories'   => [],
        'plans'        => [],
        'ad_categories'=> [],
        'users'        => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('screens')) {
            $this->artisan('migrate')->assertExitCode(0);
        }
    }

    protected function tearDown(): void
    {
        // Safety net: if a test failed mid-transaction, release any lock
        // held on the shared connection so it cannot poison later tests.
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // bookings cascade to booking_screens / booking_activities /
        // booking_pricing_breakdowns. The forked child process inserts its
        // own booking row that the parent never tracks, so also remove any
        // booking referencing THIS test's screen/fleet fixtures.
        $bookingIds = $this->createdIds['bookings'];
        if (!empty($this->createdIds['screens'])) {
            $bookingIds = array_merge(
                $bookingIds,
                DB::table('booking_screens')
                    ->whereIn('screen_id', $this->createdIds['screens'])
                    ->pluck('booking_id')
                    ->all()
            );
        }
        $bookingIds = array_values(array_unique(array_map('intval', $bookingIds)));
        if (!empty($bookingIds)) {
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
        }
        if (!empty($this->createdIds['users'])) {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $this->createdIds['users'])->delete();
            DB::table('users')->whereIn('id', $this->createdIds['users'])->delete();
        }
        foreach (['screens', 'cities', 'states', 'countries'] as $table) {
            if (!empty($this->createdIds[$table])) {
                DB::table($table)->whereIn('id', $this->createdIds[$table])->delete();
            }
        }
        if (!empty($this->createdIds['categories'])) {
            DB::table('screen_categories')->whereIn('id', $this->createdIds['categories'])->delete();
        }
        if (!empty($this->createdIds['plans'])) {
            DB::table('pricing_plans')->whereIn('id', $this->createdIds['plans'])->delete();
        }
        if (!empty($this->createdIds['ad_categories'])) {
            DB::table('advertisement_categories')->whereIn('id', $this->createdIds['ad_categories'])->delete();
        }

        parent::tearDown();
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function track(string $table, int $id): void
    {
        $this->createdIds[$table][] = $id;
    }

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $this->track('users', $user->id);
        return $user;
    }

    private function makeFleet(int $fleetSize): Screen
    {
        $country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $this->track('countries', $country->id);
        $state = State::create(['name' => 'Kerala', 'country_id' => $country->id]);
        $this->track('states', $state->id);
        $city = City::create(['name' => 'Kochi', 'state_id' => $state->id, 'country_id' => $country->id]);
        $this->track('cities', $city->id);

        $adCategory = AdvertisementCategory::create([
            'name' => 'General', 'slug' => 'general-concurrency', 'status' => 'active',
        ]);
        $this->track('ad_categories', $adCategory->id);

        $category = ScreenCategory::create(['name' => 'Mall', 'slug' => 'mall-concurrency', 'status' => 'active']);
        $this->track('categories', $category->id);

        $plan = PricingPlan::create([
            'name' => 'Standard Daily', 'base_price' => 500, 'currency' => 'INR',
            'minimum_duration' => 1, 'minimum_duration_type' => 'Day',
            'is_default' => true, 'is_active' => true,
        ]);
        $this->track('plans', $plan->id);

        $fleet = Screen::create([
            'name' => 'Concurrency Fleet',
            'screen_code' => 'MOB-CONC-' . uniqid(),
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'screen_category_id' => $category->id,
            'country_id' => $country->id,
            'location_name' => 'Base Depot',
            'pricing_plan_id' => $plan->id,
            'fleet_size' => $fleetSize,
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $this->track('screens', $fleet->id);

        return $fleet;
    }

    private function campaignPayload(int $screenId, int $quantity, string $from, string $to): array
    {
        return [
            'campaign_title' => 'Concurrency Campaign',
            'ad_frequency' => 'every_5_min',
            'start_time' => $from . ' 00:00:00',
            'end_time' => $to . ' 23:59:59',
            'screen_ids' => [$screenId],
            'quantities' => [$screenId => $quantity],
            'total_screens' => 1,
            'subtotal' => 0,
            'discount_amount' => 0,
            'referral_discount' => 0,
            'final_amount' => 0,
            'redeem_points' => 0,
            'advertisement_category_id' => AdvertisementCategory::where('slug', 'general-concurrency')->value('id'),
        ];
    }

    // ─── Raw-PDO helpers (second DB connection) ──────────────────────────

    private function secondConnection(): PDO
    {
        $config = config('database.connections.mysql');
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Try to take the inventory row lock on a second connection with a 1s
     * lock-wait timeout.
     *
     * @return bool true if the lock attempt BLOCKED (lock wait timeout),
     *              false if the lock was acquired.
     */
    private function attemptLock(PDO $pdo, int $screenId): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT id FROM screens WHERE id = ? FOR UPDATE');
            $stmt->execute([$screenId]);
            $stmt->fetchAll();
            return false;
        } catch (\PDOException $e) {
            return str_contains(strtolower($e->getMessage()), 'lock wait timeout')
                || str_contains($e->getMessage(), '1205');
        }
    }

    /**
     * Insert a capacity-consuming booking (booking + booking_screen) —
     * the exact rows the controller writes after its availability check.
     */
    private function insertBooking(int $screenId, int $quantity, string $from, string $to): int
    {
        $bookingId = DB::table('bookings')->insertGetId([
            'screen_id' => null,
            'title' => 'Fixture concurrency booking',
            'advertiser_name' => 'Test Advertiser',
            'start_time' => $from . ' 00:00:00',
            'end_time' => $to . ' 23:59:59',
            'price' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('booking_screens')->insert([
            'booking_id' => $bookingId,
            'screen_id' => $screenId,
            'quantity' => $quantity,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->track('bookings', $bookingId);
        return $bookingId;
    }

    // ─── Tests ───────────────────────────────────────────────────────────

    /**
     * Deterministic proof of the locking mechanism: while a first
     * transaction holds the fleet row lock (in-flight booking), a second
     * connection cannot even READ the row for update; after commit it can,
     * and its availability read then observes the committed booking.
     */
    public function test_fleet_row_lock_blocks_second_transaction_until_first_commits(): void
    {
        $fleet = $this->makeFleet(1);
        $pdoB = $this->secondConnection();

        // T1: lock the exact fleet row (what the booking flow does first).
        DB::beginTransaction();
        DB::table('screens')->where('id', $fleet->id)->lockForUpdate()->first();

        // T1: availability check would pass (0 units used) → insert booking,
        // but do NOT commit yet — simulates the in-flight first booking.
        $this->insertBooking($fleet->id, 1, '2026-11-01', '2026-11-05');

        // T2: second "user" tries to take the same lock → must block.
        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdoB->beginTransaction();
        $this->assertTrue(
            $this->attemptLock($pdoB, $fleet->id),
            'Second transaction must block on the fleet row lock while the first booking is in-flight.'
        );
        $pdoB->rollBack();

        // T1 commits.
        DB::commit();

        // T2: now acquires the lock and reads the COMMITTED booking.
        $pdoB->beginTransaction();
        $this->assertFalse(
            $this->attemptLock($pdoB, $fleet->id),
            'Lock must be acquirable after the first transaction commits.'
        );
        $used = (int) $pdoB->query(
            "SELECT COALESCE(SUM(COALESCE(booking_screens.quantity, 1)), 0)
               FROM booking_screens
               JOIN bookings ON bookings.id = booking_screens.booking_id
              WHERE booking_screens.screen_id = {$fleet->id}
                AND bookings.status IN ('pending_approval','approved','scheduled','running')
                AND bookings.start_time <= '2026-11-05 23:59:59'
                AND bookings.end_time >= '2026-11-01 00:00:00'"
        )->fetchColumn();

        $this->assertEquals(1, $used, 'Second transaction must observe the committed first booking.');
        $remaining = 1 - $used; // fleet_size 1 − 1 committed unit
        $this->assertLessThan(
            1,
            $remaining,
            'Remaining units (0) must be below the requested quantity (1) → over-commit prevented.'
        );
        $pdoB->rollBack();
    }

    /**
     * Same guarantee for fixed screens: the row lock also serializes
     * concurrent bookings against a fixed asset.
     */
    public function test_fixed_screen_row_lock_blocks_second_transaction(): void
    {
        $fleet = $this->makeFleet(1);
        $screen = Screen::create([
            'name' => 'Concurrency Fixed Screen',
            'screen_code' => 'FIX-CONC-' . uniqid(),
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'screen_category_id' => ScreenCategory::where('slug', 'mall-concurrency')->value('id'),
            'country_id' => Country::where('code', 'IN')->value('id'),
            'city_id' => City::where('name', 'Kochi')->value('id'),
            'location_name' => 'Kochi Mall',
            'pricing_plan_id' => PricingPlan::where('name', 'Standard Daily')->value('id'),
            'status' => 'active',
            'booking_status' => 'available',
        ]);
        $this->track('screens', $screen->id);

        $pdoB = $this->secondConnection();

        DB::beginTransaction();
        DB::table('screens')->where('id', $screen->id)->lockForUpdate()->first();

        $pdoB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $pdoB->beginTransaction();
        $this->assertTrue($this->attemptLock($pdoB, $screen->id), 'Fixed screen row must be locked.');
        $pdoB->rollBack();

        DB::rollBack();

        $pdoB->beginTransaction();
        $this->assertFalse(
            $this->attemptLock($pdoB, $screen->id),
            'Fixed screen lock must be released after the first transaction ends.'
        );
        $pdoB->rollBack();
    }

    /**
     * End-to-end (real HTTP kernel): with a fleet of exactly one unit,
     * the first booking succeeds and the second is rejected with an
     * availability conflict. Exactly one booking_screen row may exist.
     */
    public function test_second_booking_rejected_after_first_consumes_last_unit(): void
    {
        $fleet = $this->makeFleet(1);
        $user = $this->makeUser();
        $token = $user->createToken('concurrency')->plainTextToken;
        $payload = $this->campaignPayload($fleet->id, 1, '2026-11-01', '2026-11-05');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $first = $this->postJson('/api/bookings/campaign', $payload, $headers);
        $first->assertStatus(201);

        $second = $this->postJson('/api/bookings/campaign', $payload, $headers);
        $second->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase(
            'no longer available',
            $second->json('message')
        );

        $this->assertEquals(
            1,
            DB::table('booking_screens')->where('screen_id', $fleet->id)->count(),
            'Only one booking may exist for a single-unit fleet.'
        );
    }

    /**
     * True concurrency: two independent processes fire the real booking
     * request at the same instant. Exactly one succeeds.
     *
     * Skipped when pcntl is unavailable (e.g. some CI images) — the
     * deterministic raw-PDO tests above still cover the mechanism.
     */
    public function test_true_concurrent_bookings_cannot_over_commit_fleet(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for the fork-based race test.');
        }

        $fleet = $this->makeFleet(1);
        $user = $this->makeUser();
        $token = $user->createToken('concurrency')->plainTextToken;
        $payload = $this->campaignPayload($fleet->id, 1, '2026-11-01', '2026-11-05');

        $barrier = tempnam(sys_get_temp_dir(), 'bp6-barrier-');
        $result = tempnam(sys_get_temp_dir(), 'bp6-result-');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork() must succeed.');
        if ($pid === 0) {
            // ── Child: fresh Laravel app + fresh DB connection ──
            file_put_contents($barrier, 'child-ready');
            for ($i = 0; $i < 500; $i++) {
                if (@file_get_contents($barrier) === 'go') {
                    break;
                }
                usleep(2000);
            }
            $app = require __DIR__ . '/../../bootstrap/app.php';
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $request = \Illuminate\Http\Request::create(
                '/api/bookings/campaign',
                'POST',
                $payload,
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]
            );
            try {
                $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
                $response = $kernel->handle($request);
                $status = $response->getStatusCode();
                $kernel->terminate($request, $response);
                file_put_contents($result, (string) $status);
            } catch (\Throwable $e) {
                file_put_contents($result, 'ERR:' . get_class($e) . ':' . $e->getMessage());
            }
            exit(0);
        }

        // ── Parent: wait for the child to be ready, then release both. ──
        for ($i = 0; $i < 500; $i++) {
            if (@file_get_contents($barrier) === 'child-ready') {
                break;
            }
            usleep(2000);
        }
        file_put_contents($barrier, 'go');

        $parentResponse = $this->postJson(
            '/api/bookings/campaign',
            $payload,
            ['Authorization' => 'Bearer ' . $token]
        );
        $parentStatus = $parentResponse->getStatusCode();

        // Wait for the child with a generous timeout so a hung child cannot
        // hang the whole suite.
        $deadline = microtime(true) + 60;
        do {
            $waited = pcntl_waitpid($pid, $childWait, WNOHANG);
            if ($waited === $pid || $waited === -1) {
                break;
            }
            if (microtime(true) > $deadline) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $childWait);
                $this->fail('Concurrent booking child process timed out.');
            }
            usleep(10000);
        } while (true);

        $childOutput = (string) @file_get_contents($result);
        if (str_starts_with($childOutput, 'ERR:')) {
            $this->fail('Concurrent booking child process errored: ' . $childOutput);
        }
        $childStatus = (int) $childOutput;

        @unlink($barrier);
        @unlink($result);

        $statuses = [$parentStatus, $childStatus];
        sort($statuses);
        $this->assertEquals(
            [201, 422],
            $statuses,
            'Concurrent bookings for the last available unit: exactly one must succeed, the other must be rejected.'
        );

        $this->assertEquals(
            1,
            DB::table('booking_screens')->where('screen_id', $fleet->id)->count(),
            'The fleet may only ever have one committed booking_screen row.'
        );
    }
}
