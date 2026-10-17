<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reward_transactions', function (Blueprint $table) {
            // Composite index for FIFO queries used in redemption logic
            $table->index(['reward_account_id', 'type', 'created_at'], 'idx_reward_tx_account_type_created');

            // Index for points expiry queries
            $table->index(['type', 'expiry_date'], 'idx_reward_tx_type_expiry');

            // Index for period-based aggregations (reports)
            $table->index(['type', 'created_at'], 'idx_reward_tx_type_created');
        });

        Schema::table('reward_accounts', function (Blueprint $table) {
            // Index for top customer queries
            $table->index('lifetime_earned', 'idx_reward_account_earned');
            $table->index('balance', 'idx_reward_account_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reward_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_reward_tx_account_type_created');
            $table->dropIndex('idx_reward_tx_type_expiry');
            $table->dropIndex('idx_reward_tx_type_created');
        });

        Schema::table('reward_accounts', function (Blueprint $table) {
            $table->dropIndex('idx_reward_account_earned');
            $table->dropIndex('idx_reward_account_balance');
        });
    }
};
