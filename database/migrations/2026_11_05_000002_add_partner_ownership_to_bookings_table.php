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
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'created_by_partner_id')) {
                $table->foreignId('created_by_partner_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('partners')
                    ->nullOnDelete();

                $table->index('created_by_partner_id');
            }

            if (!Schema::hasColumn('bookings', 'created_by_user_id')) {
                $table->foreignId('created_by_user_id')
                    ->nullable()
                    ->after('created_by_partner_id')
                    ->constrained('users')
                    ->nullOnDelete();

                $table->index('created_by_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'created_by_partner_id')) {
                $table->dropForeign(['created_by_partner_id']);
                $table->dropColumn('created_by_partner_id');
            }

            if (Schema::hasColumn('bookings', 'created_by_user_id')) {
                $table->dropForeign(['created_by_user_id']);
                $table->dropColumn('created_by_user_id');
            }
        });
    }
};
