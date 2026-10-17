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
        if (!Schema::hasTable('mobile_asset_coverage_cities')) {
            Schema::create('mobile_asset_coverage_cities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mobile_asset_id')->constrained('screens')->onDelete('cascade');
                $table->foreignId('city_id')->constrained('cities')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['mobile_asset_id', 'city_id'], 'unique_mobile_asset_coverage_city');
                $table->index(['city_id', 'mobile_asset_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_asset_coverage_cities');
    }
};
