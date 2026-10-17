<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SEC-02 security-contract change.
     *
     * /api/v2/finance/* was registered with NO auth middleware, so these tests passed
     * anonymously. sprint_6_backend_architecture.md documents these endpoints as the
     * ADMIN "Finance Overview" dashboard, so they now require `settlements.view`.
     *
     * The FINANCIAL ASSERTIONS ARE DELIBERATELY UNCHANGED - the same fields, the same
     * structure, the same expectations. Only the caller identity changed. Removing a
     * field or loosening an assertion here would hide a real financial regression.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::create([
            'name' => 'Finance Admin',
            'email' => 'finance-api-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]));
    }

    /** @test */
    public function it_can_retrieve_financial_summary()
    {
        $response = $this->getJson('/api/v2/finance/summary');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'gross_revenue',
                    'payments_received',
                    'provider_settlements_paid',
                    'pending_settlements_count',
                    'platform_commission',
                    'refunds_issued',
                    'currency',
                ]
            ]);
    }

    /** @test */
    public function it_can_retrieve_accounting_ledger()
    {
        $response = $this->getJson('/api/v2/finance/ledger');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
    }
}
