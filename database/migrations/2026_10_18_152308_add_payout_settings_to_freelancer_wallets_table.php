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
        Schema::table('freelancer_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('freelancer_wallets', 'payout_settings')) {
                $table->json('payout_settings')->nullable()->after('lifetime_earnings');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('freelancer_wallets', function (Blueprint $table) {
            if (Schema::hasColumn('freelancer_wallets', 'payout_settings')) {
                $table->dropColumn('payout_settings');
            }
        });
    }
};
