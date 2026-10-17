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
        Schema::table('booking_screens', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_screens', 'quantity')) {
                $table->integer('quantity')->unsigned()->default(1)->after('screen_id');
            }
            if (!Schema::hasColumn('booking_screens', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('booking_screens', 'line_subtotal')) {
                $table->decimal('line_subtotal', 10, 2)->nullable()->after('unit_price');
            }
            if (!Schema::hasColumn('booking_screens', 'provider_commission')) {
                $table->decimal('provider_commission', 10, 2)->nullable()->after('line_subtotal');
            }
            if (!Schema::hasColumn('booking_screens', 'platform_commission')) {
                $table->decimal('platform_commission', 10, 2)->nullable()->after('provider_commission');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_screens', function (Blueprint $table) {
            $table->dropColumn([
                'quantity',
                'unit_price',
                'line_subtotal',
                'provider_commission',
                'platform_commission',
            ]);
        });
    }
};
