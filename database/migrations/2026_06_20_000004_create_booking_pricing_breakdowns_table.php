<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pricing_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_plan_id')->nullable()->constrained('pricing_plans')->nullOnDelete();
            $table->date('campaign_date');
            $table->decimal('runtime_hours', 8, 2)->default(0);
            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('peak_charge', 12, 2)->default(0);
            $table->decimal('weekend_charge', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('referral_discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('final_total', 12, 2)->default(0);
            $table->json('pricing_snapshot')->nullable()->comment('Snapshot of all pricing rules applied');
            $table->timestamps();

            $table->index(['booking_id', 'screen_id']);
            $table->index(['booking_id', 'campaign_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_pricing_breakdowns');
    }
};
