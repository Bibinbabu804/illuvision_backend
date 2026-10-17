<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Country;
use App\Models\State;
use App\Models\City;
use App\Models\ScreenCategory;
use App\Models\PricingPlan;
use App\Models\Screen;
use App\Models\ScreenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AssetExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Country $india;
    protected State $kerala;
    protected City $kochi;
    protected ScreenCategory $billboardCategory;
    protected PricingPlan $dailyPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'superadmin',
            'status' => 'active',
        ]);

        $this->india = Country::create(['name' => 'India', 'code' => 'IN', 'phone_code' => '+91', 'is_active' => true]);
        $this->kerala = State::create(['name' => 'Kerala', 'country_id' => $this->india->id, 'is_active' => true]);
        $this->kochi = City::create(['name' => 'Kochi', 'state_id' => $this->kerala->id, 'country_id' => $this->india->id, 'is_active' => true]);

        $this->billboardCategory = ScreenCategory::firstOrCreate(
            ['slug' => 'led-billboard'],
            ['name' => 'LED Billboard', 'status' => 'active']
        );
        $this->dailyPlan = PricingPlan::firstOrCreate(
            ['name' => 'Standard Daily Export Plan'],
            [
                'base_price' => 1000,
                'minimum_duration_type' => 'day',
                'minimum_duration_value' => 1,
                'currency' => 'INR',
                'status' => 'active',
            ]
        );

        // Create 20 screens in Kochi
        for ($i = 1; $i <= 20; $i++) {
            Screen::create([
                'name' => "Kochi Screen #{$i}",
                'screen_code' => sprintf('AST-KOC-%03d', $i),
                'asset_type' => 'fixed',
                'screen_type' => 'Outdoor Billboard',
                'location_name' => "Kochi Location #{$i}",
                'country_id' => $this->india->id,
                'city_id' => $this->kochi->id,
                'screen_category_id' => $this->billboardCategory->id,
                'pricing_plan_id' => $this->dailyPlan->id,
                'status' => 'active',
                'owner_type' => 'direct_illuvision',
                'fleet_size' => 1,
            ]);
        }
    }

    /** @test */
    public function it_exports_all_filtered_records_to_excel_ignoring_ui_pagination()
    {
        // Request export with per_page=5 (UI limit) but export should contain all 20 records
        $response = $this->actingAs($this->admin)
            ->get("/api/admin/assets/export?format=xlsx&country_id={$this->india->id}&city_id={$this->kochi->id}&per_page=5");

        $response->assertStatus(200);
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition'));

        $filePath = $response->getFile()->getPathname();
        $this->assertFileExists($filePath);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals('ILLUVISION — ASSET MANAGEMENT EXPORT', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Total Assets: 20', $sheet->getCell('D2')->getValue());
        $this->assertEquals('AST-KOC-001', $sheet->getCell('A6')->getValue());
        $this->assertEquals('Kochi Screen #1', $sheet->getCell('B6')->getValue());
    }

    /** @test */
    public function it_exports_filtered_document_with_applied_filter_summary()
    {
        $response = $this->actingAs($this->admin)
            ->get("/api/admin/assets/export?format=docx&country_id={$this->india->id}&state_id={$this->kerala->id}&city_id={$this->kochi->id}");

        $response->assertStatus(200);
        $this->assertStringContainsString('wordprocessingml.document', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.docx', $response->headers->get('Content-Disposition'));

        $filePath = $response->getFile()->getPathname();
        $this->assertFileExists($filePath);

        // Verify that the generated file is a valid Zip/OpenXML Word archive containing word/document.xml
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($filePath));
        $docXml = $zip->getFromName('word/document.xml');
        $this->assertNotFalse($docXml);
        $this->assertStringContainsString('ILLUVISION', $docXml);
        $this->assertStringContainsString('Kochi', $docXml);
        $this->assertStringContainsString('AST-KOC-001', $docXml);
        $zip->close();
    }

    /** @test */
    public function it_enforces_authentication_for_export()
    {
        $response = $this->getJson("/api/admin/assets/export?format=xlsx");
        $response->assertStatus(401);
    }
}
