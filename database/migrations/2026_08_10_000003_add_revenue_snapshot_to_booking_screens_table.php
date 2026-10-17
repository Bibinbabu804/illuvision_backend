<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_screens', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_screens', 'owner_type')) {
                $table->string('owner_type')->nullable()->after('status');
            }
            if (!Schema::hasColumn('booking_screens', 'screen_provider_id')) {
                $table->foreignId('screen_provider_id')->nullable()->after('owner_type');
            }
            if (!Schema::hasColumn('booking_screens', 'screen_provider_name')) {
                $table->string('screen_provider_name')->nullable()->after('screen_provider_id');
            }
            if (!Schema::hasColumn('booking_screens', 'illuvision_percentage')) {
                $table->decimal('illuvision_percentage', 5, 2)->nullable()->after('screen_provider_name');
            }
            if (!Schema::hasColumn('booking_screens', 'screen_provider_percentage')) {
                $table->decimal('screen_provider_percentage', 5, 2)->nullable()->after('illuvision_percentage');
            }
            if (!Schema::hasColumn('booking_screens', 'gross_revenue')) {
                $table->decimal('gross_revenue', 12, 2)->nullable()->after('screen_provider_percentage');
            }
            if (!Schema::hasColumn('booking_screens', 'platform_share')) {
                $table->decimal('platform_share', 12, 2)->nullable()->after('gross_revenue');
            }
            if (!Schema::hasColumn('booking_screens', 'provider_share')) {
                $table->decimal('provider_share', 12, 2)->nullable()->after('platform_share');
            }
            if (!Schema::hasColumn('booking_screens', 'settlement_status')) {
                $table->string('settlement_status')->default('pending')->after('provider_share');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_screens', function (Blueprint $table) {
            $columns = [
                'owner_type', 'screen_provider_id', 'screen_provider_name',
                'illuvision_percentage', 'screen_provider_percentage',
                'gross_revenue', 'platform_share', 'provider_share', 'settlement_status',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('booking_screens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
