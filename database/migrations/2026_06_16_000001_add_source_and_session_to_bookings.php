<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 20)->default('website')->after('notes')->comment('website, admin, manual');
            $table->timestamp('session_started_at')->nullable()->after('source');
            $table->timestamp('session_ended_at')->nullable()->after('session_started_at');
            $table->timestamp('payment_due_at')->nullable()->after('session_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['source', 'session_started_at', 'session_ended_at', 'payment_due_at']);
        });
    }
};
