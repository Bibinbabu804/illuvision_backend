<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function seedGeography(): Country
    {
        $country = Country::create(['name' => 'Testland', 'code' => 'TL', 'is_active' => true]);

        $north = State::create(['country_id' => $country->id, 'name' => 'North', 'is_active' => true]);
        $south = State::create(['country_id' => $country->id, 'name' => 'South', 'is_active' => true]);

        foreach (['Alpha', 'Beta'] as $name) {
            City::create(['country_id' => $country->id, 'state_id' => $north->id, 'name' => $name, 'is_active' => true]);
        }
        foreach (['Gamma', 'Delta'] as $name) {
            City::create(['country_id' => $country->id, 'state_id' => $south->id, 'name' => $name, 'is_active' => true]);
        }

        return $country;
    }

    public function test_cities_by_country_can_be_scoped_to_selected_states()
    {
        $country = $this->seedGeography();
        $south = State::where('country_id', $country->id)->where('name', 'South')->first();

        $response = $this->getJson("/api/countries/{$country->id}/cities?state_ids={$south->id}");

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Gamma'])
            ->assertJsonFragment(['name' => 'Delta'])
            ->assertJsonMissing(['name' => 'Alpha']);
    }

    public function test_cities_by_country_supports_array_state_ids()
    {
        $country = $this->seedGeography();

        $stateIds = State::where('country_id', $country->id)->pluck('id')->implode(',');

        $response = $this->getJson("/api/countries/{$country->id}/cities?state_ids={$stateIds}");

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_cities_by_country_search_finds_cities_beyond_first_page()
    {
        $country = $this->seedGeography();
        $north = State::where('country_id', $country->id)->where('name', 'North')->first();

        $response = $this->getJson(
            "/api/countries/{$country->id}/cities?search=Alph&perPage=1&state_ids={$north->id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonFragment(['name' => 'Alpha']);
    }

    public function test_cities_by_country_paginates()
    {
        $country = $this->seedGeography();

        $pageOne = $this->getJson("/api/countries/{$country->id}/cities?perPage=2&page=1");
        $pageOne->assertStatus(200)->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');

        $pageTwo = $this->getJson("/api/countries/{$country->id}/cities?perPage=2&page=2");
        $pageTwo->assertStatus(200)->assertJsonCount(2, 'data');

        $idsOne = collect($pageOne->json('data'))->pluck('id')->all();
        $idsTwo = collect($pageTwo->json('data'))->pluck('id')->all();
        $this->assertEmpty(array_intersect($idsOne, $idsTwo));
    }
}
