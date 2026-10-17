<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('verification_status')->nullable()->after('payment_status'); // pending, verified, failed
            $table->timestamp('captured_at')->nullable()->after('paid_at');
            $table->text('error_message')->nullable()->after('captured_at');
            $table->string('razorpay_refund_id')->nullable()->after('refund_status');
            $table->index('payment_status');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'captured_at', 'error_message', 'razorpay_refund_id']);
        });
    }
};
