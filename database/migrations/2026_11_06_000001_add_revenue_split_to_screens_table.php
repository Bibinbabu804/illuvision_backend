<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            if (!Schema::hasColumn('screens', 'illuvision_percentage')) {
                $table->decimal('illuvision_percentage', 5, 2)->default(10.00)->after('screen_provider_id');
            }
            if (!Schema::hasColumn('screens', 'screen_provider_percentage')) {
                $table->decimal('screen_provider_percentage', 5, 2)->default(90.00)->after('illuvision_percentage');
            }
        });

        // Initialize existing provider-owned screens from their screen_providers default split
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("
                UPDATE screens s
                INNER JOIN screen_providers sp ON s.screen_provider_id = sp.id
                SET s.illuvision_percentage = COALESCE(sp.illuvision_percentage, 10.00),
                    s.screen_provider_percentage = COALESCE(sp.screen_provider_percentage, 90.00)
                WHERE s.owner_type = 'screen_provider' AND s.screen_provider_id IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn(['illuvision_percentage', 'screen_provider_percentage']);
        });
    }
};
