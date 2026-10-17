<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            if (!Schema::hasColumn('screens', 'owner_type')) {
                $table->string('owner_type')->default('illuvision')->after('pricing_plan_id');
            }
            if (!Schema::hasColumn('screens', 'screen_provider_id')) {
                $table->foreignId('screen_provider_id')->nullable()->after('owner_type')
                    ->constrained('screen_providers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            if (Schema::hasColumn('screens', 'screen_provider_id')) {
                $table->dropForeign(['screen_provider_id']);
                $table->dropColumn('screen_provider_id');
            }
            if (Schema::hasColumn('screens', 'owner_type')) {
                $table->dropColumn('owner_type');
            }
        });
    }
};
