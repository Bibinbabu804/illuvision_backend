<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\State;
use App\Models\City;
use Illuminate\Database\Seeder;

class LocationHierarchySeeder extends Seeder
{
    public function run(): void
    {
        // 1. India States & Cities
        $india = Country::firstOrCreate(
            ['name' => 'India'],
            ['code' => 'IN', 'is_active' => true]
        );

        $kerala = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Kerala'],
            ['code' => 'KL', 'is_active' => true]
        );

        $tamilNadu = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Tamil Nadu'],
            ['code' => 'TN', 'is_active' => true]
        );

        $karnataka = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Karnataka'],
            ['code' => 'KA', 'is_active' => true]
        );

        $maharashtra = State::firstOrCreate(
            ['country_id' => $india->id, 'name' => 'Maharashtra'],
            ['code' => 'MH', 'is_active' => true]
        );

        // Map Kerala Cities
        $keralaCities = ['Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur', 'Alappuzha', 'Kollam', 'Kannur', 'Palakkad','Pathanamthitta'];
        foreach ($keralaCities as $cityName) {
            City::where('country_id', $india->id)->where('name', $cityName)->update(['state_id' => $kerala->id]);
            City::firstOrCreate(
                ['country_id' => $india->id, 'name' => $cityName],
                ['state_id' => $kerala->id, 'is_active' => true]
            );
        }

        // Map Tamil Nadu Cities
        $tnCities = ['Chennai', 'Coimbatore', 'Madurai', 'Salem', 'Tiruchirappalli'];
        foreach ($tnCities as $cityName) {
            City::firstOrCreate(
                ['country_id' => $india->id, 'name' => $cityName],
                ['state_id' => $tamilNadu->id, 'is_active' => true]
            );
        }

        // Map Karnataka Cities
        $kaCities = ['Bengaluru', 'Mysuru', 'Mangaluru', 'Hubballi'];
        foreach ($kaCities as $cityName) {
            City::firstOrCreate(
                ['country_id' => $india->id, 'name' => $cityName],
                ['state_id' => $karnataka->id, 'is_active' => true]
            );
        }

        // Map Maharashtra Cities
        $mhCities = ['Mumbai', 'Pune', 'Nagpur', 'Nashik'];
        foreach ($mhCities as $cityName) {
            City::firstOrCreate(
                ['country_id' => $india->id, 'name' => $cityName],
                ['state_id' => $maharashtra->id, 'is_active' => true]
            );
        }

        // 2. United States States & Cities
        $us = Country::firstOrCreate(
            ['name' => 'United States'],
            ['code' => 'US', 'is_active' => true]
        );

        $california = State::firstOrCreate(
            ['country_id' => $us->id, 'name' => 'California'],
            ['code' => 'CA', 'is_active' => true]
        );

        $newYork = State::firstOrCreate(
            ['country_id' => $us->id, 'name' => 'New York'],
            ['code' => 'NY', 'is_active' => true]
        );

        $usCaCities = ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose'];
        foreach ($usCaCities as $cityName) {
            City::firstOrCreate(
                ['country_id' => $us->id, 'name' => $cityName],
                ['state_id' => $california->id, 'is_active' => true]
            );
        }

        $usNyCities = ['New York City', 'Buffalo', 'Rochester', 'Albany'];
        foreach ($usNyCities as $cityName) {
            City::firstOrCreate(
                ['country_id' => $us->id, 'name' => $cityName],
                ['state_id' => $newYork->id, 'is_active' => true]
            );
        }

        // Ensure any city without state_id gets assigned to default state for its country if missing
        City::whereNull('state_id')->where('country_id', $india->id)->update(['state_id' => $kerala->id]);
        City::whereNull('state_id')->where('country_id', $us->id)->update(['state_id' => $california->id]);
    }
}
