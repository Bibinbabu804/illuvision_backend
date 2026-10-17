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
        // 1. Add reward columns to the bookings table
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'reward_points_earned')) {
                $table->integer('reward_points_earned')->default(0)->after('final_amount');
            }
            if (!Schema::hasColumn('bookings', 'reward_points_redeemed')) {
                $table->integer('reward_points_redeemed')->default(0)->after('reward_points_earned');
            }
            if (!Schema::hasColumn('bookings', 'reward_discount_amount')) {
                $table->decimal('reward_discount_amount', 10, 2)->default(0.00)->after('reward_points_redeemed');
            }
        });

        // 2. Reward Accounts Table
        if (!Schema::hasTable('reward_accounts')) {
            Schema::create('reward_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->integer('balance')->default(0);
                $table->integer('lifetime_earned')->default(0);
                $table->integer('lifetime_redeemed')->default(0);
                $table->integer('lifetime_expired')->default(0);
                $table->timestamps();
            });
        }

        // 3. Reward Transactions Ledger Table
        if (!Schema::hasTable('reward_transactions')) {
            Schema::create('reward_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reward_account_id')->constrained('reward_accounts')->onDelete('cascade');
                $table->foreignId('booking_id')->nullable()->constrained('bookings')->onDelete('set null');
                $table->string('type', 30); // earn, redeem, expire, adjustment, refund_reversal, admin_bonus
                $table->integer('points');
                $table->integer('points_used')->default(0);
                $table->string('description', 255);
                $table->text('reason')->nullable();
                $table->foreignId('admin_id')->nullable()->constrained('users')->onDelete('set null');
                $table->dateTime('expiry_date')->nullable();
                $table->timestamps();
            });
        }

        // 4. Reward Redemptions Mapping Table
        if (!Schema::hasTable('reward_redemptions')) {
            Schema::create('reward_redemptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
                $table->foreignId('reward_transaction_id')->constrained('reward_transactions')->onDelete('cascade');
                $table->integer('points_redeemed');
                $table->decimal('discount_amount', 10, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('reward_transactions');
        Schema::dropIfExists('reward_accounts');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['reward_points_earned', 'reward_points_redeemed', 'reward_discount_amount']);
        });
    }
};
