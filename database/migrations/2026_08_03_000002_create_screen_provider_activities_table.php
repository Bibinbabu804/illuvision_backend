<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_provider_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_provider_id')->constrained('screen_providers')->cascadeOnDelete();
            $table->string('type', 50);
            $table->text('description');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_provider_activities');
    }
};
