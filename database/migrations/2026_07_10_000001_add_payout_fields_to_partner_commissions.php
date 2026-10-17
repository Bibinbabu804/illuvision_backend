<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            if (!Schema::hasColumn('partner_commissions', 'payout_date')) {
                $table->date('payout_date')->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('partner_commissions', 'payout_reference')) {
                $table->string('payout_reference', 100)->nullable()->after('payout_date');
            }
            if (!Schema::hasColumn('partner_commissions', 'payout_method')) {
                $table->string('payout_method', 50)->nullable()->after('payout_reference');
            }
            if (!Schema::hasColumn('partner_commissions', 'payout_notes')) {
                $table->text('payout_notes')->nullable()->after('payout_method');
            }
            if (!Schema::hasColumn('partner_commissions', 'approved_by')) {
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('payout_notes');
            }
            if (!Schema::hasColumn('partner_commissions', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partner_commissions', function (Blueprint $table) {
            $table->dropColumn([
                'payout_date',
                'payout_reference',
                'payout_method',
                'payout_notes',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};
