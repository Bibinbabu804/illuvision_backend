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
        Schema::table('screens', function (Blueprint $table) {
            $table->string('booking_status')->default('available')->after('status');
            $table->string('sold_out_reason')->nullable()->after('booking_status');
            $table->timestamp('sold_out_from')->nullable()->after('sold_out_reason');
            $table->timestamp('sold_out_until')->nullable()->after('sold_out_from');
            $table->text('admin_notes')->nullable()->after('sold_out_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn([
                'booking_status',
                'sold_out_reason',
                'sold_out_from',
                'sold_out_until',
                'admin_notes'
            ]);
        });
    }
};
