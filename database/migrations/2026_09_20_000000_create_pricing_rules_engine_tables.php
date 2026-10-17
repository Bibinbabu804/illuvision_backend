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
        // 1. Add currency column to pricing_plans
        if (Schema::hasTable('pricing_plans') && !Schema::hasColumn('pricing_plans', 'currency')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->string('currency', 10)->default('INR')->after('base_price');
            });
        }

        // 2. Create pricing_screen_rules table
        Schema::create('pricing_screen_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->integer('minimum_screens');
            $table->integer('maximum_screens')->nullable();
            $table->string('discount_type')->default('Percentage'); // Percentage, Fixed
            $table->decimal('discount_value', 12, 2)->default(0.00);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });

        // 3. Create pricing_duration_rules table
        Schema::create('pricing_duration_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->decimal('minimum_duration', 8, 2);
            $table->decimal('maximum_duration', 8, 2)->nullable();
            $table->string('duration_unit')->default('Month'); // Hour, Day, Week, Month
            $table->string('discount_type')->default('Percentage'); // Percentage, Fixed
            $table->decimal('discount_value', 12, 2)->default(0.00);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });

        // 4. Drop and recreate pricing_peak_rules table
        Schema::dropIfExists('pricing_peak_rules');
        Schema::create('pricing_peak_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->string('day_of_week', 50)->default('All'); // All, Mon, Tue, etc. or comma-separated
            $table->time('start_time');
            $table->time('end_time');
            $table->string('pricing_type')->default('multiplier'); // multiplier, percentage, fixed
            $table->decimal('value', 12, 2)->default(1.00);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });

        // 5. Drop and recreate pricing_weekend_rules table
        Schema::dropIfExists('pricing_weekend_rules');
        Schema::create('pricing_weekend_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();
            $table->string('days', 100)->default('Sat,Sun'); // Comma-separated or JSON
            $table->string('pricing_type')->default('multiplier'); // multiplier, percentage, fixed
            $table->decimal('value', 12, 2)->default(1.00);
            $table->string('status', 20)->default('active'); // active, inactive
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_weekend_rules');
        Schema::dropIfExists('pricing_peak_rules');
        Schema::dropIfExists('pricing_duration_rules');
        Schema::dropIfExists('pricing_screen_rules');

        if (Schema::hasTable('pricing_plans') && Schema::hasColumn('pricing_plans', 'currency')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
