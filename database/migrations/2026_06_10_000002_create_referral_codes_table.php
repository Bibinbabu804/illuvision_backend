<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('status')->default('draft');
            $table->date('start_date');
            $table->date('expiry_date');
            $table->integer('usage_limit')->default(0)->comment('0 = unlimited');
            $table->integer('used_count')->default(0);
            $table->string('discount_type');
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->string('commission_type');
            $table->decimal('commission_value', 10, 2);
            $table->decimal('max_commission_amount', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_codes');
    }
};
