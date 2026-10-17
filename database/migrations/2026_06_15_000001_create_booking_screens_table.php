<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_screens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->json('pricing_snapshot')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['booking_id', 'screen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_screens');
    }
};
