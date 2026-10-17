<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_source', 30)->nullable()->after('source')
                ->comment('whatsapp, email, call, offline_sales, partner, admin_manual');
            $table->foreignId('created_by_admin_id')->nullable()->after('booking_source')
                ->constrained('users')->nullOnDelete();
            $table->text('sales_notes')->nullable()->after('notes');
            $table->text('internal_notes')->nullable()->after('sales_notes');
            $table->string('payment_mode', 30)->nullable()->after('payment_due_at')
                ->comment('razorpay, bank_transfer, cash, upi, manual, pending');
            $table->string('offline_payment_reference', 255)->nullable()->after('payment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['created_by_admin_id']);
            $table->dropColumn([
                'booking_source',
                'created_by_admin_id',
                'sales_notes',
                'internal_notes',
                'payment_mode',
                'offline_payment_reference',
            ]);
        });
    }
};
