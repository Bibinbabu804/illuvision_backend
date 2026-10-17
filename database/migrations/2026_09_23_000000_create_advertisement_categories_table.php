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
        Schema::create('advertisement_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->string('status', 20)->default('active'); // active, inactive
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('advertisement_category_id')
                ->nullable()
                ->after('pricing_plan_id')
                ->constrained('advertisement_categories')
                ->nullOnDelete();

            $table->string('advertisement_category_name')
                ->nullable()
                ->after('advertisement_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'advertisement_category_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropForeign(['advertisement_category_id']);
                $table->dropColumn(['advertisement_category_id', 'advertisement_category_name']);
            });
        }

        Schema::dropIfExists('advertisement_categories');
    }
};
