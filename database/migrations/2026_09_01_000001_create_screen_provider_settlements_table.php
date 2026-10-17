<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_provider_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_provider_id')->constrained('screen_providers')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_revenue', 14, 2)->default(0);
            $table->decimal('platform_revenue', 14, 2)->default(0);
            $table->decimal('provider_revenue', 14, 2)->default(0);
            $table->integer('booking_count')->default(0);
            $table->integer('screen_count')->default(0);
            $table->string('status')->default('generated');
            $table->string('reference_number')->nullable()->unique();
            $table->foreignId('generated_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['screen_provider_id', 'status']);
            $table->index(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_provider_settlements');
    }
};
