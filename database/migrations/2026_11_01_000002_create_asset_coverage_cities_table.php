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
        if (!Schema::hasTable('asset_coverage_cities')) {
            Schema::create('asset_coverage_cities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('screens')->onDelete('cascade');
                $table->foreignId('country_id')->nullable()->constrained('countries')->onDelete('set null');
                $table->unsignedBigInteger('state_id')->nullable();
                $table->foreignId('city_id')->constrained('cities')->onDelete('cascade');
                $table->integer('priority')->default(1);
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->unique(['asset_id', 'city_id'], 'unique_asset_coverage_city');
                $table->index(['city_id', 'asset_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_coverage_cities');
    }
};
