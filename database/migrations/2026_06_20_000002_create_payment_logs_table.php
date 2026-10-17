<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('event'); // order_created, payment_initiated, payment_captured, verification_completed, refund_initiated, refund_processed, booking_submitted, booking_approved
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('payment_id');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_logs');
    }
};
