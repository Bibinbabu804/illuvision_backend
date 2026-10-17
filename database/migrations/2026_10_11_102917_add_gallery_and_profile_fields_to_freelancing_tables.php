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
        if (Schema::hasTable('freelancer_services') && !Schema::hasColumn('freelancer_services', 'gallery')) {
            Schema::table('freelancer_services', function (Blueprint $table) {
                $table->json('gallery')->nullable()->after('featured_image');
            });
        }

        if (Schema::hasTable('freelancer_profiles')) {
            Schema::table('freelancer_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('freelancer_profiles', 'response_time')) {
                    $table->string('response_time', 50)->nullable()->after('availability');
                }
                if (!Schema::hasColumn('freelancer_profiles', 'seller_level')) {
                    $table->string('seller_level', 50)->nullable()->after('response_time');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('freelancer_services') && Schema::hasColumn('freelancer_services', 'gallery')) {
            Schema::table('freelancer_services', function (Blueprint $table) {
                $table->dropColumn('gallery');
            });
        }

        if (Schema::hasTable('freelancer_profiles')) {
            Schema::table('freelancer_profiles', function (Blueprint $table) {
                if (Schema::hasColumn('freelancer_profiles', 'response_time')) {
                    $table->dropColumn('response_time');
                }
                if (Schema::hasColumn('freelancer_profiles', 'seller_level')) {
                    $table->dropColumn('seller_level');
                }
            });
        }
    }
};
