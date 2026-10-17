<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event');                    // e.g. 'booking_created'
            $table->string('recipient_type');            // 'admin', 'user', 'partner'
            $table->string('recipient_email')->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('channel');                   // 'email', 'in_app', 'sms', etc.
            $table->string('status');                    // 'sent', 'failed', 'queued', 'skipped'
            $table->text('failed_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('event');
            $table->index('status');
            $table->index('recipient_type');
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
