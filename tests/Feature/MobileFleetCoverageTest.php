<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\Screen;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileFleetCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_fleet_hierarchical_coverage_filtering()
    {
        $country = Country::firstOrCreate(['code' => 'IN'], ['name' => 'India']);
        $kerala = State::create(['name' => 'Kerala', 'country_id' => $country->id]);
        $tamilNadu = State::create(['name' => 'Tamil Nadu', 'country_id' => $country->id]);

        $kochi = City::create(['name' => 'Kochi', 'state_id' => $kerala->id, 'country_id' => $country->id]);
        $thrissur = City::create(['name' => 'Thrissur', 'state_id' => $kerala->id, 'country_id' => $country->id]);
        $chennai = City::create(['name' => 'Chennai', 'state_id' => $tamilNadu->id, 'country_id' => $country->id]);

        // Fixed Display in Kochi
        $fixedDisplay = Screen::create([
            'name' => 'Kochi LED Billboard',
            'screen_code' => 'FIXED-01',
            'asset_type' => 'fixed',
            'screen_type' => 'LED Billboard',
            'country_id' => $country->id,
            'city_id' => $kochi->id,
            'location_name' => 'MG Road Kochi',
            'status' => 'active',
        ]);

        // Mobile Fleet covering Kochi & Thrissur in Kerala
        $mobileFleet = Screen::create([
            'name' => 'Kerala Express Fleet',
            'screen_code' => 'FLEET-01',
            'asset_type' => 'mobile',
            'screen_type' => 'Digital Van',
            'country_id' => $country->id,
            'location_name' => 'Base Depot Kochi',
            'status' => 'active',
        ]);

        $mobileFleet->coverageStates()->attach([$kerala->id]);
        $mobileFleet->coverageCities()->attach([$kochi->id, $thrissur->id]);

        // 1. Filter by Kochi City: should return both fixed display and mobile fleet
        $kochiAssets = Screen::forCity($kochi->id)->get();
        $this->assertTrue($kochiAssets->contains($fixedDisplay));
        $this->assertTrue($kochiAssets->contains($mobileFleet));

        // 2. Filter by Thrissur City: should return mobile fleet, but NOT fixed display
        $thrissurAssets = Screen::forCity($thrissur->id)->get();
        $this->assertFalse($thrissurAssets->contains($fixedDisplay));
        $this->assertTrue($thrissurAssets->contains($mobileFleet));

        // 3. Filter by Chennai City: should return neither
        $chennaiAssets = Screen::forCity($chennai->id)->get();
        $this->assertFalse($chennaiAssets->contains($fixedDisplay));
        $this->assertFalse($chennaiAssets->contains($mobileFleet));

        // 4. Filter by Kerala State: should return both
        $keralaAssets = Screen::forState($kerala->id)->get();
        $this->assertTrue($keralaAssets->contains($fixedDisplay));
        $this->assertTrue($keralaAssets->contains($mobileFleet));

        // 5. Filter by Tamil Nadu State: should return neither
        $tnAssets = Screen::forState($tamilNadu->id)->get();
        $this->assertFalse($tnAssets->contains($fixedDisplay));
        $this->assertFalse($tnAssets->contains($mobileFleet));
    }
}
