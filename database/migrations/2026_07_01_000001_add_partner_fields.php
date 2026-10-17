<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('city')->nullable()->after('phone');
            $table->string('profession')->nullable()->after('city');
            $table->string('company')->nullable()->after('profession');
            $table->text('message')->nullable()->after('company');
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->string('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'city', 'profession', 'company', 'message',
                'user_id', 'reviewed_by', 'approved_at', 'rejected_at',
            ]);
            $table->string('status')->default('active')->change();
        });
    }
};
