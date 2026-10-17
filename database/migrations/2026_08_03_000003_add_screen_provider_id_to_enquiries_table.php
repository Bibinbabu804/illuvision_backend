<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_provider_enquiries', function (Blueprint $table) {
            $table->foreignId('screen_provider_id')->nullable()->after('assigned_to')->constrained('screen_providers')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('screen_provider_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('screen_provider_enquiries', function (Blueprint $table) {
            $table->dropForeign(['screen_provider_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['screen_provider_id', 'approved_by', 'approved_at', 'rejection_reason']);
        });
    }
};
