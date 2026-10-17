<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('first_booking_discount_applied')->default(false)->after('referral_discount');
            $table->decimal('first_booking_discount_amount', 12, 2)->default(0.00)->after('first_booking_discount_applied');
        });

        Schema::table('booking_pricing_breakdowns', function (Blueprint $table) {
            $table->decimal('first_booking_discount', 12, 2)->default(0.00)->after('referral_discount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['first_booking_discount_applied', 'first_booking_discount_amount']);
        });

        Schema::table('booking_pricing_breakdowns', function (Blueprint $table) {
            $table->dropColumn('first_booking_discount');
        });
    }
};
