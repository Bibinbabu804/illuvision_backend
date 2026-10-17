<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fetch all mobile screens
        $mobileScreens = DB::table('screens')
            ->where('asset_type', '!=', 'fixed')
            ->get();

        foreach ($mobileScreens as $screen) {
            // Derive states and cities from existing asset_coverage_cities or locations or screen country/city/state
            $cityIds = DB::table('asset_coverage_cities')
                ->where('asset_id', $screen->id)
                ->pluck('city_id')
                ->toArray();

            if (!empty($screen->city_id) && !in_array($screen->city_id, $cityIds)) {
                $cityIds[] = $screen->city_id;
            }

            // Insert into mobile_asset_coverage_cities
            foreach ($cityIds as $cityId) {
                DB::table('mobile_asset_coverage_cities')->updateOrInsert(
                    ['mobile_asset_id' => $screen->id, 'city_id' => $cityId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }

            // Get state_ids corresponding to these cities, plus screen's state if any
            $stateIds = DB::table('cities')
                ->whereIn('id', $cityIds)
                ->whereNotNull('state_id')
                ->pluck('state_id')
                ->unique()
                ->toArray();

            foreach ($stateIds as $stateId) {
                DB::table('mobile_asset_coverage_states')->updateOrInsert(
                    ['mobile_asset_id' => $screen->id, 'state_id' => $stateId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('mobile_asset_coverage_cities')->truncate();
        DB::table('mobile_asset_coverage_states')->truncate();
    }
};
