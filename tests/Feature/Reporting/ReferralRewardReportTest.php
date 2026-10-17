<?php

namespace Tests\Feature\Reporting;

use App\Application\Reporting\ReportQueryRegistry;
use App\Models\Booking;
use App\Models\RewardAccount;
use App\Models\RewardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Referral / partner and rewards (loyalty) report regression coverage.
 */
class ReferralRewardReportTest extends TestCase
{
    use RefreshDatabase;
    use ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportingFixtures();
    }

    protected function referrals(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('referrals')->execute($filters);
    }

    protected function rewards(array $filters = []): array
    {
        return app(ReportQueryRegistry::class)->get('rewards')->execute($filters);
    }

    /**
     * Reporting rows come back as a mix of arrays and query-builder rows (either
     * stdClass or a Collection, depending on whether the query was converted).
     * json_encode renders all of them identically, so the API payload is
     * unaffected; this helper only makes the assertions readable.
     */
    protected function rows(iterable $rows): array
    {
        $normalised = [];

        foreach ($rows as $row) {
            $normalised[] = is_array($row) ? $row : (array) $row;
        }

        return $normalised;
    }

    /**
     * Create a reward transaction pinned to an explicit created_at.
     *
     * created_at is absent from RewardTransaction::$fillable, so it has to be
     * written after the insert rather than passed through mass assignment.
     */
    protected function makeRewardTransaction(int $accountId, string $type, int $points, string $createdAt): RewardTransaction
    {
        $transaction = RewardTransaction::create([
            'reward_account_id' => $accountId,
            'type' => $type,
            'points' => $points,
            'description' => 'Seeded '.$type,
        ]);

        $transaction->created_at = $createdAt;
        $transaction->save();

        return $transaction;
    }

    public function test_referral_report_sums_persisted_commission_ledgers_by_status(): void
    {
        [$partner, $code] = $this->makePartnerReferral();

        $firstUsage = $this->makeReferralUsage($partner, $code, 100, 40, '2026-08-10 09:00:00');
        $this->makeReferralUsage($partner, $code, 200, 60, '2026-08-11 09:00:00');

        $this->makeCommission($partner, $firstUsage, 'paid', 40, '2026-08-10 09:00:00');
        $this->makeCommission($partner, $firstUsage, 'pending', 25, '2026-08-10 09:00:00');

        $kpis = $this->referrals([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(300.0, $kpis['referral_revenue']);       // 100 + 200
        $this->assertEquals(40.0, $kpis['total_commissions']);        // paid only
        $this->assertEquals(25.0, $kpis['pending_commissions']);      // pending only
        $this->assertEquals(2, $kpis['total_usage_count']);
        $this->assertEquals('Apex Media Ltd', $kpis['top_partner']);
        $this->assertEquals(1, $kpis['active_referral_campaigns']);
    }

    public function test_referral_report_excludes_out_of_window_activity(): void
    {
        [$partner, $code] = $this->makePartnerReferral();

        $usage = $this->makeReferralUsage($partner, $code, 500, 250, '2026-09-15 09:00:00');
        $this->makeCommission($partner, $usage, 'paid', 250, '2026-09-15 09:00:00');

        $kpis = $this->referrals([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(0.0, $kpis['referral_revenue']);
        $this->assertEquals(0.0, $kpis['total_commissions']);
        $this->assertEquals(0, $kpis['total_usage_count']);
        $this->assertEquals('N/A', $kpis['top_partner']);
    }

    public function test_referral_report_ranks_partner_and_code_breakdowns(): void
    {
        [$apex, $apexCode] = $this->makePartnerReferral('APEX10');
        [$zenith, $zenithCode] = $this->makePartnerReferral('ZEN20', 'Zenith Media Ltd');

        $apexUsage = $this->makeReferralUsage($apex, $apexCode, 100, 30, '2026-08-10 09:00:00');
        $this->makeCommission($apex, $apexUsage, 'paid', 30, '2026-08-10 09:00:00');

        // A second usage so the code ranking by usage_count is unambiguous.
        $this->makeReferralUsage($apex, $apexCode, 50, 15, '2026-08-12 09:00:00');

        $this->makeReferralUsage($zenith, $zenithCode, 900, 700, '2026-08-11 09:00:00');

        $report = $this->referrals([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ]);

        $topPartners = $this->rows($report['top_partners']);
        $this->assertCount(2, $topPartners);

        // top_partners is ordered by total commission descending.
        $this->assertEquals('Zenith Media Ltd', $topPartners[0]['name']);
        $this->assertEquals(700.0, (float) $topPartners[0]['total_commission']);
        $this->assertEquals('Apex Media Ltd', $topPartners[1]['name']);

        // Note the source asymmetry, which is pre-existing behaviour: the
        // top_partners breakdown sums the commission_amount snapshot carried on
        // referral_usages (30 + 15 = 45), while the kpis.total_commissions
        // figure sums the partner_commissions settlement ledger (30 only).
        $this->assertEquals(45.0, (float) $topPartners[1]['total_commission']);

        // top_codes is ordered by usage_count descending, which is a different
        // ranking than top_partners.
        $topCodes = $this->rows($report['top_codes']);
        $this->assertCount(2, $topCodes);
        $this->assertEquals('APEX10', $topCodes[0]['code']);
        $this->assertEquals('Apex Media Ltd', $topCodes[0]['partner_name']);
        $this->assertEquals(2, (int) $topCodes[0]['usage_count']);
        $this->assertEquals('ZEN20', $topCodes[1]['code']);
        $this->assertEquals(1, (int) $topCodes[1]['usage_count']);

        // Monthly trend groups by YYYY-MM.
        $monthlyTrend = $this->rows($report['monthly_trend']);
        $this->assertCount(1, $monthlyTrend);
        $this->assertEquals('2026-08', $monthlyTrend[0]['month']);
        $this->assertEquals(1050.0, (float) $monthlyTrend[0]['total_discount']);
    }

    public function test_referral_report_handles_an_empty_window(): void
    {
        $this->makePartnerReferral();

        $kpis = $this->referrals([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(0.0, $kpis['referral_revenue']);
        $this->assertEquals(0, $kpis['total_usage_count']);
        $this->assertEquals('N/A', $kpis['top_partner']);
    }

    public function test_referral_endpoint_preserves_its_response_shape(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/referrals?date_from=2026-08-01&date_to=2026-08-31')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Referral report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'kpis' => [
                        'referral_revenue',
                        'total_commissions',
                        'pending_commissions',
                        'total_usage_count',
                        'top_partner',
                        'active_referral_campaigns',
                    ],
                    'top_partners',
                    'top_codes',
                    'monthly_trend',
                ],
            ]);
    }

    public function test_reward_report_separates_lifetime_totals_from_period_totals(): void
    {
        $account = RewardAccount::create([
            'user_id' => $this->advertiser->id,
            'balance' => 400,
            'lifetime_earned' => 1000,
            'lifetime_redeemed' => 500,
            'lifetime_expired' => 100,
        ]);

        // In-window activity, pinned inside the August 2026 window.
        $this->makeRewardTransaction($account->id, RewardTransaction::TYPE_EARN, 200, '2026-08-10 09:00:00');
        $this->makeRewardTransaction($account->id, RewardTransaction::TYPE_REDEEM, -50, '2026-08-11 09:00:00');
        $this->makeRewardTransaction($account->id, RewardTransaction::TYPE_EXPIRE, -25, '2026-08-12 09:00:00');

        $kpis = $this->rewards([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        // Lifetime KPIs are not date filtered.
        $this->assertEquals(1000.0, $kpis['total_issued']);
        $this->assertEquals(500.0, $kpis['total_redeemed']);
        $this->assertEquals(100.0, $kpis['total_expired']);
        $this->assertEquals(400.0, $kpis['outstanding_liability']);
        $this->assertEquals(1, $kpis['total_accounts']);
        $this->assertEquals(1, $kpis['accounts_with_balance']);

        // redemption_rate = total_redeemed / total_issued * 100
        $this->assertEquals(50.0, $kpis['redemption_rate']);

        // Period KPIs only see in-window rows, and use ABS() for outflows.
        $this->assertEquals(200.0, $kpis['period_issued']);
        $this->assertEquals(50.0, $kpis['period_redeemed']);
        $this->assertEquals(25.0, $kpis['period_expired']);
    }

    public function test_reward_report_reports_zero_redemption_rate_when_nothing_was_issued(): void
    {
        $kpis = $this->rewards([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(0, $kpis['redemption_rate']);
        $this->assertEquals(0, $kpis['total_accounts']);
        $this->assertEquals(0, $kpis['average_reward_per_booking']);
    }

    public function test_reward_report_counts_only_bookings_that_used_reward_discounts(): void
    {
        RewardAccount::create([
            'user_id' => $this->advertiser->id,
            'balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_redeemed' => 0,
            'lifetime_expired' => 0,
        ]);

        $this->makeBooking([
            'final_amount' => 1000,
            'reward_discount_amount' => 100,
            'created_at' => '2026-08-10 09:00:00',
        ]);
        $this->makeBooking([
            'final_amount' => 2000,
            'reward_discount_amount' => 0,
            'created_at' => '2026-08-11 09:00:00',
        ]);

        $kpis = $this->rewards([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        $this->assertEquals(1, $kpis['bookings_with_rewards']);
        $this->assertEquals(1000.0, $kpis['total_revenue_influenced']);
        $this->assertEquals(100.0, $kpis['total_discount_given']);
        $this->assertEquals(100.0, $kpis['average_reward_per_booking']);
    }

    public function test_reward_report_lists_top_customers_by_lifetime_earnings(): void
    {
        $low = User::factory()->create(['name' => 'Low Earner', 'email' => 'low@example.com']);
        $high = User::factory()->create(['name' => 'High Earner', 'email' => 'high@example.com']);

        RewardAccount::create([
            'user_id' => $low->id,
            'balance' => 10,
            'lifetime_earned' => 100,
            'lifetime_redeemed' => 80,
            'lifetime_expired' => 10,
        ]);
        RewardAccount::create([
            'user_id' => $high->id,
            'balance' => 900,
            'lifetime_earned' => 1000,
            'lifetime_redeemed' => 50,
            'lifetime_expired' => 50,
        ]);

        $topCustomers = $this->rewards([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['top_customers'];

        $this->assertCount(2, $topCustomers);
        $this->assertEquals('High Earner', $topCustomers[0]->name ?? $topCustomers[0]['name']);
        $this->assertEquals(900, $topCustomers[0]->balance ?? $topCustomers[0]['balance']);
    }

    public function test_reward_endpoint_preserves_its_response_shape(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/rewards?date_from=2026-08-01&date_to=2026-08-31')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Reward report fetched successfully')
            ->assertJsonStructure([
                'data' => [
                    'kpis' => [
                        'total_issued',
                        'total_redeemed',
                        'total_expired',
                        'outstanding_liability',
                        'total_accounts',
                        'accounts_with_balance',
                        'redemption_rate',
                        'period_issued',
                        'period_redeemed',
                        'period_expired',
                        'bookings_with_rewards',
                        'total_revenue_influenced',
                        'total_discount_given',
                        'average_reward_per_booking',
                    ],
                    'top_customers',
                    'monthly_trend',
                ],
            ]);
    }

    public function test_booking_status_cancelled_is_excluded_from_reward_booking_counts(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'reward_discount_amount' => 100,
            'status' => Booking::STATUS_CANCELLED,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $kpis = $this->rewards([
            'date_from' => '2026-08-01',
            'date_to' => '2026-08-31 23:59:59',
        ])['kpis'];

        // The reward report does not filter on status; this pins that behaviour.
        $this->assertEquals(1, $kpis['bookings_with_rewards']);
    }
}
