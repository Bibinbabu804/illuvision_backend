<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('completed_at');
            $table->text('dispute_reason')->nullable()->after('cancellation_reason');
            $table->timestamp('disputed_at')->nullable()->after('dispute_reason');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropColumn(['cancellation_reason', 'dispute_reason', 'disputed_at']);
        });
    }
};
