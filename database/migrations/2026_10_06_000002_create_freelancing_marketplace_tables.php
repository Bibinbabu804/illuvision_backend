<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('freelancer_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('freelancer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('freelancer_service_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('requested')->index();
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('platform_fee', 14, 2)->default(0);
            $table->decimal('freelancer_amount', 14, 2)->default(0);
            $table->unsignedInteger('delivery_days')->nullable();
            $table->unsignedInteger('revision_limit')->default(0);
            $table->unsignedInteger('revision_count')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_id', 'status']);
            $table->index(['freelancer_profile_id', 'status']);
        });

        Schema::create('freelance_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('freelancer_profile_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedInteger('delivery_days');
            $table->unsignedInteger('revisions')->default(0);
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'freelancer_profile_id'], 'fp_project_freelancer_unique');
        });

        Schema::create('freelance_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('freelance_projects')->nullOnDelete();
            $table->foreignId('freelancer_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('freelance_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('freelance_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id'], 'fc_participant_unique');
        });

        Schema::create('freelance_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('freelance_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->string('message_type', 20)->default('text');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('freelance_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('freelance_messages')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('freelance_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->unsignedInteger('revision_number')->default(0);
            $table->boolean('is_final')->default(false);
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('freelance_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('freelance_projects')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->string('gateway', 30)->default('razorpay');
            $table->string('gateway_order_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('gateway_signature')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('INR');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('held_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('freelance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('freelancer_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('review')->nullable();
            $table->string('status', 20)->default('published');
            $table->timestamps();
        });

        Schema::create('freelancer_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('available_balance', 14, 2)->default(0);
            $table->decimal('pending_balance', 14, 2)->default(0);
            $table->decimal('lifetime_earnings', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('freelancer_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('freelancer_wallets')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('freelance_projects')->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference')->unique();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'created_at']);
        });

        Schema::create('freelancer_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_profile_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30);
            $table->string('account_reference');
            $table->string('status', 20)->default('pending')->index();
            $table->text('admin_note')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['freelancer_withdrawals', 'freelancer_wallet_transactions', 'freelancer_wallets', 'freelance_reviews', 'freelance_payments', 'freelance_deliverables', 'freelance_message_attachments', 'freelance_messages', 'freelance_conversation_participants', 'freelance_conversations', 'freelance_proposals', 'freelance_projects'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
