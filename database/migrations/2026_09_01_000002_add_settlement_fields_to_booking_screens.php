<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_screens', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_screens', 'settlement_id')) {
                $table->foreignId('settlement_id')->nullable()->after('settlement_status')
                    ->constrained('screen_provider_settlements')->nullOnDelete();
            }
            if (!Schema::hasColumn('booking_screens', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('settlement_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('booking_screens', function (Blueprint $table) {
            if (Schema::hasColumn('booking_screens', 'settlement_id')) {
                $table->dropForeign(['settlement_id']);
                $table->dropColumn('settlement_id');
            }
            if (Schema::hasColumn('booking_screens', 'settled_at')) {
                $table->dropColumn('settled_at');
            }
        });
    }
};
