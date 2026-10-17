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
        Schema::create('screen_duration_split_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_id')->constrained('screens')->cascadeOnDelete();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->foreignId('pricing_duration_rule_id')->constrained('pricing_duration_rules')->cascadeOnDelete();
            $table->decimal('illuvision_percentage', 5, 2)->default(10.00);
            $table->decimal('screen_provider_percentage', 5, 2)->default(90.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Unique constraint: Exactly ONE duration split configuration per Screen + Plan + Duration Rule
            $table->unique(
                ['screen_id', 'pricing_plan_id', 'pricing_duration_rule_id'],
                'uq_screen_plan_duration'
            );

            // Lookup index for point queries during resolution
            $table->index(
                ['screen_id', 'pricing_plan_id', 'is_active'],
                'idx_screen_plan_active'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screen_duration_split_overrides');
    }
};
