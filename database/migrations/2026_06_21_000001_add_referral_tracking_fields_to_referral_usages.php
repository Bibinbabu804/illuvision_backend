<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_usages', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('user_id')
                ->constrained('payments')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->after('payment_id')
                ->constrained('partners')->nullOnDelete();
            $table->decimal('final_booking_amount', 12, 2)->default(0)->after('commission_amount');
            $table->timestamp('used_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('referral_usages', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['partner_id']);
            $table->dropColumn(['payment_id', 'partner_id', 'final_booking_amount', 'used_at']);
        });
    }
};
