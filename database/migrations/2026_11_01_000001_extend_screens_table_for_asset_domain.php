<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            if (!Schema::hasColumn('screens', 'asset_type')) {
                $table->string('asset_type', 50)->default('fixed')->after('screen_code');
            }
            if (!Schema::hasColumn('screens', 'pricing_unit')) {
                $table->string('pricing_unit', 50)->default('per_screen')->after('screen_type');
            }
            if (!Schema::hasColumn('screens', 'fleet_size')) {
                $table->integer('fleet_size')->unsigned()->default(1)->after('pricing_unit');
            }
            if (!Schema::hasColumn('screens', 'working_hours_pd')) {
                $table->integer('working_hours_pd')->unsigned()->nullable()->after('operating_hours');
            }
            if (!Schema::hasColumn('screens', 'travel_distance_km_pd')) {
                $table->integer('travel_distance_km_pd')->unsigned()->nullable()->after('working_hours_pd');
            }
            if (!Schema::hasColumn('screens', 'display_specs')) {
                $table->string('display_specs')->nullable()->after('resolution');
            }
            if (!Schema::hasColumn('screens', 'asset_metadata')) {
                $table->json('asset_metadata')->nullable()->after('admin_notes');
            }

            // Make city_id, latitude, longitude nullable for mobile assets
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn([
                'asset_type',
                'pricing_unit',
                'fleet_size',
                'working_hours_pd',
                'travel_distance_km_pd',
                'display_specs',
                'asset_metadata',
            ]);
        });
    }
};
