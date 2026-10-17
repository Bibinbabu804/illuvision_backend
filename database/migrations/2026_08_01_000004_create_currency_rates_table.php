<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3)->index();
            $table->decimal('exchange_rate', 14, 6);
            $table->string('base_currency', 3)->default('INR');
            $table->string('source', 50)->default('exchangerate-api');
            $table->timestamp('fetched_at')->useCurrent();
            $table->timestamps();
            $table->unique(['currency_code', 'base_currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
