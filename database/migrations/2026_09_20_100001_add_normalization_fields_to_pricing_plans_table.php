<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds three columns that preserve the original admin-entered price
     * and the exchange rate used to normalise it to INR canonical form.
     *
     * All existing plans have currency=INR — they are backfilled with
     * identity values (original = base_price, rate = 1.0) so no base_price
     * values are changed.
     */
    public function up(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            // Original administrator-entered amount (before normalisation to INR)
            $table->decimal('base_price_original', 12, 2)
                ->nullable()
                ->after('base_price')
                ->comment('Amount entered by admin in original currency before INR normalisation');

            // Currency of base_price_original
            $table->string('base_price_currency', 10)
                ->nullable()
                ->after('base_price_original')
                ->comment('ISO 4217 currency code of base_price_original (e.g. GBP, USD)');

            // Exchange rate used at normalisation time (1 unit of source = X INR)
            $table->decimal('conversion_rate_to_inr', 18, 8)
                ->nullable()
                ->after('base_price_currency')
                ->comment('Rate used to convert original price to INR: 1 source_currency = X INR');
        });

        // Backfill existing plans: all are INR — identity values, no financial change.
        DB::statement("
            UPDATE pricing_plans
            SET
                base_price_original   = base_price,
                base_price_currency   = COALESCE(NULLIF(currency, ''), 'INR'),
                conversion_rate_to_inr = 1.00000000
            WHERE base_price_original IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_plans', function (Blueprint $table) {
            $table->dropColumn([
                'base_price_original',
                'base_price_currency',
                'conversion_rate_to_inr',
            ]);
        });
    }
};
