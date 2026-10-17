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
        Schema::table('screen_providers', function (Blueprint $table) {
            if (!Schema::hasColumn('screen_providers', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('contact_enquiries', function (Blueprint $table) {
            if (!Schema::hasColumn('contact_enquiries', 'screen_provider_id')) {
                $table->foreignId('screen_provider_id')->nullable()->after('assigned_to')->constrained('screen_providers')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_enquiries', function (Blueprint $table) {
            if (Schema::hasColumn('contact_enquiries', 'screen_provider_id')) {
                $table->dropForeign(['screen_provider_id']);
                $table->dropColumn('screen_provider_id');
            }
        });

        Schema::table('screen_providers', function (Blueprint $table) {
            if (Schema::hasColumn('screen_providers', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
        });
    }
};
