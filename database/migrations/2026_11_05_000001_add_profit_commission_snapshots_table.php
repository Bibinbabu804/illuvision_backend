<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Sprint B1 – Profit-Based Partner Commission Engine
     * Persists campaign_total, provider_revenue, illuvision_revenue, partner_commission_base,
     * partner_commission_percentage, and partner_commission_amount.
     */
    public function up(): void
    {
        Schema::table('referral_usages', function (Blueprint $table) {
            $table->decimal('campaign_total', 12, 2)->default(0)->after('final_booking_amount');
            $table->decimal('provider_revenue', 12, 2)->default(0)->after('campaign_total');
            $table->decimal('illuvision_revenue', 12, 2)->default(0)->after('provider_revenue');
            $table->decimal('partner_commission_base', 12, 2)->default(0)->after('illuvision_revenue');
            $table->decimal('partner_commission_percentage', 8, 2)->default(0)->after('partner_commission_base');
        });

        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->decimal('campaign_total', 12, 2)->default(0)->after('commission_value');
            $table->decimal('provider_revenue', 12, 2)->default(0)->after('campaign_total');
            $table->decimal('illuvision_revenue', 12, 2)->default(0)->after('provider_revenue');
            $table->decimal('partner_commission_base', 12, 2)->default(0)->after('illuvision_revenue');
            $table->decimal('partner_commission_percentage', 8, 2)->default(0)->after('partner_commission_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_usages', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_total',
                'provider_revenue',
                'illuvision_revenue',
                'partner_commission_base',
                'partner_commission_percentage',
            ]);
        });

        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_total',
                'provider_revenue',
                'illuvision_revenue',
                'partner_commission_base',
                'partner_commission_percentage',
            ]);
        });
    }
};
