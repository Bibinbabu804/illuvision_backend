<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('campaign_title')->nullable()->after('title');
            $table->string('ad_frequency')->default('every_5_min')->after('notes');
            $table->unsignedInteger('total_screens')->default(0)->after('ad_frequency');
            $table->decimal('subtotal', 12, 2)->default(0)->after('price');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('subtotal');
            $table->decimal('referral_discount', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('final_amount', 12, 2)->default(0)->after('referral_discount');
            $table->text('rejection_reason')->nullable()->after('final_amount');
            $table->timestamp('approved_at')->nullable()->after('rejection_reason');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'user_id',
                'campaign_title',
                'ad_frequency',
                'total_screens',
                'subtotal',
                'discount_amount',
                'referral_discount',
                'final_amount',
                'rejection_reason',
                'approved_at',
                'approved_by',
            ]);
        });
    }
};
