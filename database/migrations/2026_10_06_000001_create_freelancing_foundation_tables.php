<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelancer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('professional_title')->nullable();
            $table->text('bio')->nullable();
            $table->string('location')->nullable();
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->json('languages')->nullable();
            $table->string('availability')->nullable();
            $table->json('social_links')->nullable();
            $table->string('verification_status', 30)->default('unverified')->index();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('total_projects')->default(0);
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('freelancer_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['freelancer_profile_id', 'slug']);
            $table->index('slug');
        });

        Schema::create('freelancer_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('category')->nullable()->index();
            $table->string('subcategory')->nullable();
            $table->json('skills')->nullable();
            $table->string('featured_image')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('freelancer_service_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('delivery_days');
            $table->unsignedInteger('revisions')->default(0);
            $table->json('features')->nullable();
            $table->timestamps();
            $table->unique(['freelancer_service_id', 'name']);
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_profile_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('project_url')->nullable();
            $table->json('skills')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('visibility', 20)->default('public')->index();
            $table->string('status', 20)->default('published')->index();
            $table->unsignedInteger('views')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('portfolio_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('media_type', 20);
            $table->string('original_name');
            $table->string('file_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['portfolio_item_id', 'media_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_media');
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('freelancer_service_packages');
        Schema::dropIfExists('freelancer_services');
        Schema::dropIfExists('freelancer_skills');
        Schema::dropIfExists('freelancer_profiles');
    }
};
