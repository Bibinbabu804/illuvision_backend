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
        if (Schema::hasTable('freelancer_services') && !Schema::hasColumn('freelancer_services', 'highlights')) {
            Schema::table('freelancer_services', function (Blueprint $table) {
                $table->json('highlights')->nullable()->after('skills');
            });
        }

        if (Schema::hasTable('freelancer_service_packages')) {
            Schema::table('freelancer_service_packages', function (Blueprint $table) {
                if (!Schema::hasColumn('freelancer_service_packages', 'title')) {
                    $table->string('title', 120)->nullable()->after('name');
                }
                if (!Schema::hasColumn('freelancer_service_packages', 'description')) {
                    $table->text('description')->nullable()->after('title');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('freelancer_services') && Schema::hasColumn('freelancer_services', 'highlights')) {
            Schema::table('freelancer_services', function (Blueprint $table) {
                $table->dropColumn('highlights');
            });
        }

        if (Schema::hasTable('freelancer_service_packages')) {
            Schema::table('freelancer_service_packages', function (Blueprint $table) {
                if (Schema::hasColumn('freelancer_service_packages', 'title')) {
                    $table->dropColumn('title');
                }
                if (Schema::hasColumn('freelancer_service_packages', 'description')) {
                    $table->dropColumn('description');
                }
            });
        }
    }
};
