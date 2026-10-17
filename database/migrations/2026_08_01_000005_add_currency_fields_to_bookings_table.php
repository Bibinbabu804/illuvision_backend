<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('final_amount')
                  ->comment('Currency code shown to user at booking (e.g. GBP, INR)');
            $table->decimal('exchange_rate_used', 14, 6)->nullable()->after('currency_code')
                  ->comment('Exchange rate from display currency to INR at booking time');
            $table->decimal('display_amount', 14, 2)->nullable()->after('exchange_rate_used')
                  ->comment('final_amount converted to user display currency');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'exchange_rate_used', 'display_amount']);
        });
    }
};
