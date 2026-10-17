<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->after('referral_usage_id')
                ->constrained('bookings')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->after('booking_id')
                ->constrained('payments')->nullOnDelete();
            $table->string('commission_type')->nullable()->after('payment_id');
            $table->decimal('commission_value', 10, 2)->default(0)->after('commission_type');
            $table->timestamp('generated_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropForeign(['payment_id']);
            $table->dropColumn(['booking_id', 'payment_id', 'commission_type', 'commission_value', 'generated_at']);
        });
    }
};
