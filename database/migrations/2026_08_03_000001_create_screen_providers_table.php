<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider_name');
            $table->string('business_name')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 20);
            $table->string('email');
            $table->string('business_type', 30)->default('individual');
            $table->string('status', 30)->default('pending_review');
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('created_from_enquiry')->default(false);
            $table->foreignId('screen_provider_enquiry_id')->nullable()->constrained('screen_provider_enquiries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_providers');
    }
};
