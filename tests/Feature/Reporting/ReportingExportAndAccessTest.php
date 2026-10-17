<?php

namespace Tests\Feature\Reporting;

use App\Application\Reporting\Exports\Contracts\ReportExporter;
use App\Application\Reporting\Exports\CsvReportExporter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Locks the reporting HTTP contract that is easy to break while extracting the
 * query layer: export formats, response envelopes, route permissions, and the
 * ReportService facade's backward compatibility.
 */
class ReportingExportAndAccessTest extends TestCase
{
    use RefreshDatabase;
    use ReportingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportingFixtures();
    }

    /**
     * Read a response body. The controller returns a buffered response, not a
     * stream, so streamedContent() must not be used here.
     */
    protected function body(\Illuminate\Testing\TestResponse $response): string
    {
        return (string) $response->getContent();
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function test_csv_export_returns_an_attachment_with_the_expected_headers(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'status' => \App\Models\Booking::STATUS_APPROVED,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/api/reports/export?type=bookings&format=csv&date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression(
            '/attachment; filename="reports_bookings_\d{8}_\d{6}\.csv"/',
            $disposition,
        );

        $body = $this->body($response);

        // Column names and ordering are part of the export contract.
        $this->assertStringStartsWith("Date,Bookings,Revenue\n", $body);
        $this->assertStringContainsString('2026-08-10', $body);
    }

    public function test_export_defaults_to_csv_when_no_format_is_supplied(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/api/reports/export?type=bookings');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_json_export_returns_the_success_envelope_instead_of_a_file(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/reports/export?type=bookings&format=json&date_from=2026-08-01&date_to=2026-08-31');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Export data fetched successfully')
            ->assertJsonStructure([
                'data' => ['overview', 'daily_volumes', 'booking_trends', 'approval_metrics', 'source_breakdown'],
            ]);
    }

    public function test_xlsx_is_accepted_by_validation_but_served_as_csv(): void
    {
        // `xlsx` passes the `in:csv,json,xlsx` rule, but there is no xlsx
        // exporter, so the CSV branch handles it. Preserved behaviour.
        $response = $this->actingAs($this->admin)
            ->get('/api/reports/export?type=bookings&format=xlsx');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    public function test_rewards_export_type_is_rejected_by_endpoint_validation(): void
    {
        // Pre-existing gap: CsvReportExporter has a fully implemented `rewards`
        // branch, and ReportService::getRewardReport() exists, but the endpoint
        // validation list omits `rewards`, so the branch is unreachable over
        // HTTP. Exposing it would change an externally observable contract and
        // is therefore out of scope for Phase 0.11. The column layout is still
        // covered directly against the exporter below.
        $this->actingAs($this->admin)
            ->getJson('/api/reports/export?type=rewards&format=csv')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_rewards_csv_export_keeps_its_summary_block_and_blank_separator(): void
    {
        // The rewards export has an unusual three-block layout: a summary row,
        // then a blank separator row, then a "TOP CUSTOMERS" header. This is
        // part of the existing export contract and is asserted against the
        // exporter directly because the endpoint cannot reach this branch.
        $csv = app(ReportExporter::class)->export('rewards', [
            'kpis' => [
                'total_accounts' => 3,
                'total_issued' => 1000,
                'total_redeemed' => 400,
                'total_expired' => 50,
                'redemption_rate' => 40,
                'period_issued' => 250,
                'period_redeemed' => 100,
            ],
            'top_customers' => [
                ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'balance' => 600, 'lifetime_earned' => 900, 'lifetime_redeemed' => 250, 'lifetime_expired' => 50],
            ],
        ])['body'];

        $lines = explode("\n", $csv);

        $this->assertStringStartsWith('Customer,Email,Balance', $lines[0]);
        $this->assertStringStartsWith('SUMMARY,', $lines[1]);
        $this->assertStringContainsString('40%', $lines[1]);
        $this->assertSame('', trim($lines[2]));

        // fputcsv quotes any field containing whitespace.
        $this->assertStringStartsWith('"TOP CUSTOMERS",,Balance', $lines[3]);
        $this->assertStringStartsWith('"Asha Rao",asha@example.com,600,900,250,50', $lines[4]);
    }

    public function test_screen_providers_export_is_accepted_but_still_emits_headers_only(): void
    {
        $this->makeBooking([
            'final_amount' => 1000,
            'status' => \App\Models\Booking::STATUS_APPROVED,
            'created_at' => '2026-08-10 09:00:00',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/api/reports/export?type=screen-providers&format=csv');

        $response->assertStatus(200);

        $body = $this->body($response);

        // Pre-existing gap: `screen-providers` is a valid `type` and the exporter
        // has a column layout for it, but the controller has no match arm that
        // produces the payload, so `[]` is rendered as a header row only.
        // Wiring it up would change an externally observable export and is
        // therefore out of scope for Phase 0.11.
        $this->assertStringStartsWith('Provider,"Owned Screens",Campaigns', $body);
        $this->assertSame(1, substr_count(trim($body), "\n") + 1);
    }

    public function test_export_rejects_an_unknown_type(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/export?type=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_export_requires_a_type(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/reports/export')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_the_controller_resolves_the_exporter_through_the_container_binding(): void
    {
        // Guards the AppServiceProvider binding so a future rename cannot
        // silently break the controller constructor.
        $this->assertInstanceOf(CsvReportExporter::class, app(ReportExporter::class));
    }

    public function test_the_export_contract_shape_is_respected_by_the_exporter(): void
    {
        $exporter = app(ReportExporter::class);

        $result = $exporter->export('bookings', []);

        $this->assertMatchesRegularExpression('/^reports_bookings_\d{8}_\d{6}\.csv$/', $result['fileName']);
        $this->assertSame('text/csv', $result['contentType']);
        $this->assertArrayHasKey('body', $result);
    }

    public function test_the_controller_calls_the_injected_exporter_rather_than_concrete_csv(): void
    {
        $captured = [];

        $this->mock(ReportExporter::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('export')
                ->once()
                ->andReturnUsing(function ($type, $data) use (&$captured) {
                    $captured['type'] = $type;
                    $captured['is_array'] = is_array($data);

                    return [
                        'fileName' => 'injected.csv',
                        'contentType' => 'text/csv',
                        'body' => 'a,b',
                    ];
                });
        });

        $response = $this->actingAs($this->admin)
            ->get('/api/reports/export?type=bookings');

        $response->assertStatus(200);
        $this->assertSame('bookings', $captured['type']);
        $this->assertTrue($captured['is_array']);
        $this->assertStringContainsString('injected.csv', (string) $response->headers->get('Content-Disposition'));
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_reporting_endpoints_require_authentication(): void
    {
        foreach ([
            '/api/reports/overview',
            '/api/reports/revenue',
            '/api/reports/bookings',
            '/api/reports/screens',
            '/api/reports/cities',
            '/api/reports/campaigns',
            '/api/reports/categories',
            '/api/reports/referrals',
            '/api/reports/rewards',
            '/api/reports/screen-providers',
        ] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_advertisers_without_reports_view_are_forbidden(): void
    {
        foreach ([
            '/api/reports/overview',
            '/api/reports/revenue',
            '/api/reports/bookings',
            '/api/reports/screens',
            '/api/reports/cities',
            '/api/reports/campaigns',
            '/api/reports/categories',
            '/api/reports/referrals',
            '/api/reports/rewards',
        ] as $url) {
            $this->actingAs($this->advertiser)
                ->getJson($url)
                ->assertForbidden();
        }
    }

    public function test_screen_providers_route_is_guarded_by_its_own_permission_not_reports_view(): void
    {
        // Routing asymmetry that predates Phase 0.11: this endpoint lives in the
        // screen_providers.view group, not the reports.view group.
        $this->makeRoleWithPermissions('provider-viewer', ['screen_providers.view']);

        $user = User::factory()->create(['role' => 'provider-viewer', 'status' => 'active']);

        $this->actingAs($user)
            ->getJson('/api/reports/screen-providers')
            ->assertStatus(200);
    }

    public function test_export_requires_the_reports_export_permission(): void
    {
        $this->makeRoleWithPermissions('report-viewer', ['reports.view']);

        $user = User::factory()->create(['role' => 'report-viewer', 'status' => 'active']);

        // reports.view alone is not enough: the export route adds
        // permission:reports.export on top of the group middleware.
        $this->actingAs($user)
            ->getJson('/api/reports/export?type=bookings')
            ->assertForbidden();

        $this->makeRoleWithPermissions('report-exporter', ['reports.view', 'reports.export']);

        $exporter = User::factory()->create(['role' => 'report-exporter', 'status' => 'active']);

        $this->actingAs($exporter)
            ->getJson('/api/reports/export?type=bookings&format=json')
            ->assertStatus(200);
    }

    public function test_super_admin_bypasses_reporting_permissions_at_the_route(): void
    {
        // PermissionMiddleware::handle() short-circuits for super-admin/superadmin.
        // Note User::hasPermission() does *not* apply that same bypass, so route
        // behaviour is the only reliable thing to assert here.
        $this->actingAs($this->admin)->getJson('/api/reports/overview')->assertStatus(200);
        $this->actingAs($this->admin)->getJson('/api/reports/export?type=bookings&format=json')->assertStatus(200);
    }

    /*
    |--------------------------------------------------------------------------
    | Facade backward compatibility
    |--------------------------------------------------------------------------
    */

    public function test_the_report_service_facade_still_exposes_every_public_method(): void
    {
        $service = app(\App\Services\ReportService::class);

        foreach ([
            'getOverview',
            'getRevenueReport',
            'getBookingReport',
            'getScreenReport',
            'getScreenProviderReport',
            'getCityReport',
            'getCampaignReport',
            'getReferralReport',
            'getCategoryReport',
            'getRewardReport',
        ] as $method) {
            $this->assertTrue(
                method_exists($service, $method),
                "ReportService::{$method}() must remain available for existing callers.",
            );
        }
    }

    public function test_the_facade_delegates_to_the_registry(): void
    {
        $filters = ['date_from' => '2026-08-01', 'date_to' => '2026-08-31 23:59:59'];

        $viaService = app(\App\Services\ReportService::class)->getBookingReport($filters);

        $this->assertIsArray($viaService);
        $this->assertArrayHasKey('overview', $viaService);
        $this->assertArrayHasKey('approval_metrics', $viaService);
    }

    public function test_the_facade_uses_the_registry_period_defaults_when_filters_are_omitted(): void
    {
        // No filters at all: the query layer must fall back to its own defaults
        // rather than erroring, which is what the old service did.
        $report = app(\App\Services\ReportService::class)->getBookingReport();

        $this->assertIsArray($report);
        $this->assertArrayHasKey('overview', $report);
    }
}
