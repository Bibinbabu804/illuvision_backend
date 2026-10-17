<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('pricing_snapshot')->nullable()->after('final_amount')
                  ->comment('Detailed pricing breakdown snapshot from Pricing Rule Engine');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('pricing_snapshot');
        });
    }
};
