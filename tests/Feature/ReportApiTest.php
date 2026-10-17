<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'superadmin',
            'status' => 'active',
        ]);
    }

    /** @test */
    public function it_can_fetch_report_overview()
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/reports/overview');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
    }

    /** @test */
    public function it_can_fetch_revenue_report()
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/reports/revenue');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
    }

    /** @test */
    public function it_can_fetch_campaign_report()
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/reports/campaigns');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
    }

    /** @test */
    public function it_can_export_report_in_json_format()
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/reports/export?type=revenue&format=json');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
    }
}
