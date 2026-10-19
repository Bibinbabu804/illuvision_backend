<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Categories
        if (!Schema::hasTable('freelance_categories')) {
            Schema::create('freelance_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('freelance_categories')->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('type', 30)->default('both'); // service, portfolio, both
                $table->text('description')->nullable();
                $table->string('icon', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // 2. Category Requests (Feature 2 & Feature 5)
        if (!Schema::hasTable('freelance_category_requests')) {
            Schema::create('freelance_category_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('freelancer_profile_id')->nullable()->constrained('freelancer_profiles')->cascadeOnDelete();
                $table->string('name');
                $table->string('type', 30)->default('service'); // service, portfolio
                $table->foreignId('parent_id')->nullable()->constrained('freelance_categories')->nullOnDelete();
                $table->text('suggested_description')->nullable();
                $table->string('status', 20)->default('pending'); // pending, approved, rejected
                $table->text('admin_feedback')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignId('created_category_id')->nullable()->constrained('freelance_categories')->nullOnDelete();
                $table->timestamps();
            });
        }

        // 3. Extend Services (Feature 2, Feature 3, Feature 4, Feature 8)
        if (Schema::hasTable('freelancer_services')) {
            Schema::table('freelancer_services', function (Blueprint $table) {
                if (!Schema::hasColumn('freelancer_services', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('category')->constrained('freelance_categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('freelancer_services', 'is_paused')) {
                    $table->boolean('is_paused')->default(false)->after('status');
                }
                if (!Schema::hasColumn('freelancer_services', 'paused_at')) {
                    $table->timestamp('paused_at')->nullable()->after('is_paused');
                }
                if (!Schema::hasColumn('freelancer_services', 'pause_reason')) {
                    $table->string('pause_reason')->nullable()->after('paused_at');
                }
                if (!Schema::hasColumn('freelancer_services', 'video_url')) {
                    $table->string('video_url')->nullable()->after('featured_image');
                }
                if (!Schema::hasColumn('freelancer_services', 'video_path')) {
                    $table->string('video_path')->nullable()->after('video_url');
                }
                if (!Schema::hasColumn('freelancer_services', 'video_thumbnail')) {
                    $table->string('video_thumbnail')->nullable()->after('video_path');
                }
                if (!Schema::hasColumn('freelancer_services', 'video_status')) {
                    $table->string('video_status', 30)->default('approved')->after('video_thumbnail');
                }
                if (!Schema::hasColumn('freelancer_services', 'moderation_status')) {
                    $table->string('moderation_status', 30)->default('approved')->after('video_status');
                }
                if (!Schema::hasColumn('freelancer_services', 'moderation_feedback')) {
                    $table->text('moderation_feedback')->nullable()->after('moderation_status');
                }
                if (!Schema::hasColumn('freelancer_services', 'moderated_at')) {
                    $table->timestamp('moderated_at')->nullable()->after('moderation_feedback');
                }
                if (!Schema::hasColumn('freelancer_services', 'moderated_by')) {
                    $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        // 4. Extend Portfolio Items (Feature 5, Feature 8)
        if (Schema::hasTable('portfolio_items')) {
            Schema::table('portfolio_items', function (Blueprint $table) {
                if (!Schema::hasColumn('portfolio_items', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('category')->constrained('freelance_categories')->nullOnDelete();
                }
                if (!Schema::hasColumn('portfolio_items', 'moderation_status')) {
                    $table->string('moderation_status', 30)->default('approved')->after('status');
                }
                if (!Schema::hasColumn('portfolio_items', 'moderation_feedback')) {
                    $table->text('moderation_feedback')->nullable()->after('moderation_status');
                }
                if (!Schema::hasColumn('portfolio_items', 'moderated_at')) {
                    $table->timestamp('moderated_at')->nullable()->after('moderation_feedback');
                }
                if (!Schema::hasColumn('portfolio_items', 'moderated_by')) {
                    $table->foreignId('moderated_by')->nullable()->after('moderated_at')->constrained('users')->nullOnDelete();
                }
            });
        }

        // 5. Extend Portfolio Media (Feature 6)
        if (Schema::hasTable('portfolio_media')) {
            Schema::table('portfolio_media', function (Blueprint $table) {
                if (!Schema::hasColumn('portfolio_media', 'thumbnail_path')) {
                    $table->string('thumbnail_path')->nullable()->after('path');
                }
                if (!Schema::hasColumn('portfolio_media', 'is_cover')) {
                    $table->boolean('is_cover')->default(false)->after('sort_order');
                }
                if (!Schema::hasColumn('portfolio_media', 'moderation_status')) {
                    $table->string('moderation_status', 30)->default('approved')->after('is_cover');
                }
            });
        }

        // 6. Extend Projects (Feature 1, Feature 11)
        if (Schema::hasTable('freelance_projects')) {
            Schema::table('freelance_projects', function (Blueprint $table) {
                if (!Schema::hasColumn('freelance_projects', 'milestone_advance_pct')) {
                    $table->decimal('milestone_advance_pct', 5, 2)->default(30.00)->after('freelancer_amount');
                }
                if (!Schema::hasColumn('freelance_projects', 'milestone_final_pct')) {
                    $table->decimal('milestone_final_pct', 5, 2)->default(70.00)->after('milestone_advance_pct');
                }
                if (!Schema::hasColumn('freelance_projects', 'held_amount')) {
                    $table->decimal('held_amount', 14, 2)->default(0)->after('milestone_final_pct');
                }
                if (!Schema::hasColumn('freelance_projects', 'released_amount')) {
                    $table->decimal('released_amount', 14, 2)->default(0)->after('held_amount');
                }
                if (!Schema::hasColumn('freelance_projects', 'pricing_snapshot')) {
                    $table->json('pricing_snapshot')->nullable()->after('released_amount');
                }
            });
        }

        // 7. Project Milestones (Feature 1)
        if (!Schema::hasTable('freelance_project_milestones')) {
            Schema::create('freelance_project_milestones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
                $table->unsignedSmallInteger('milestone_number')->default(1);
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('percentage', 5, 2);
                $table->decimal('gross_amount', 14, 2);
                $table->decimal('freelancer_amount', 14, 2);
                $table->decimal('platform_fee', 14, 2)->default(0);
                $table->string('status', 30)->default('pending'); // pending, submitted, revision_requested, approved, released, disputed
                $table->foreignId('deliverable_id')->nullable()->constrained('freelance_deliverables')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->string('idempotency_key', 64)->nullable()->unique();
                $table->timestamps();
            });
        }

        // 8. Freelancer Unavailabilities (Feature 9)
        if (!Schema::hasTable('freelancer_unavailabilities')) {
            Schema::create('freelancer_unavailabilities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('freelancer_profile_id')->constrained('freelancer_profiles')->cascadeOnDelete();
                $table->date('from_date');
                $table->date('to_date');
                $table->string('reason')->nullable();
                $table->timestamps();
            });
        }

        // 9. Communication Moderation Records (Feature 7)
        if (!Schema::hasTable('communication_moderation_records')) {
            Schema::create('communication_moderation_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('context_type', 50); // message, profile_bio, service_desc, portfolio_desc, review_reply, deliverable_desc
                $table->unsignedBigInteger('context_id')->nullable();
                $table->json('detected_triggers');
                $table->text('original_text');
                $table->text('sanitized_text')->nullable();
                $table->string('severity', 20)->default('blocked'); // warning, blocked, flagged
                $table->string('status', 20)->default('flagged'); // flagged, reviewed, dismissed, action_taken
                $table->text('admin_notes')->nullable();
                $table->timestamps();
            });
        }

        // 10. Review Replies (Feature 13)
        if (!Schema::hasTable('freelance_review_replies')) {
            Schema::create('freelance_review_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_id')->unique()->constrained('freelance_reviews')->cascadeOnDelete();
                $table->foreignId('freelancer_profile_id')->constrained('freelancer_profiles')->cascadeOnDelete();
                $table->text('reply');
                $table->string('status', 20)->default('published');
                $table->timestamps();
            });
        }

        // 11. Deliverable Comments (Feature 13)
        if (!Schema::hasTable('freelance_deliverable_comments')) {
            Schema::create('freelance_deliverable_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deliverable_id')->constrained('freelance_deliverables')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('comment');
                $table->json('attachments')->nullable();
                $table->timestamps();
            });
        }

        // 12. Multiple Freelancer Profile Images (Feature 14)
        if (!Schema::hasTable('freelancer_profile_media')) {
            Schema::create('freelancer_profile_media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('freelancer_profile_id')->constrained('freelancer_profiles')->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name');
                $table->string('mime_type');
                $table->unsignedBigInteger('size');
                $table->boolean('is_primary')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('moderation_status', 20)->default('approved'); // pending, approved, rejected
                $table->timestamps();
            });
        }

        // 13. Extend Messages (Feature 12)
        if (Schema::hasTable('freelance_messages')) {
            Schema::table('freelance_messages', function (Blueprint $table) {
                if (!Schema::hasColumn('freelance_messages', 'is_review_request')) {
                    $table->boolean('is_review_request')->default(false)->after('message_type');
                }
                if (!Schema::hasColumn('freelance_messages', 'milestone_id')) {
                    $table->foreignId('milestone_id')->nullable()->after('is_review_request')->constrained('freelance_project_milestones')->nullOnDelete();
                }
                if (!Schema::hasColumn('freelance_messages', 'deliverable_id')) {
                    $table->foreignId('deliverable_id')->nullable()->after('milestone_id')->constrained('freelance_deliverables')->nullOnDelete();
                }
            });
        }

        // 14. Extend Deliverables (Feature 1, Feature 12)
        if (Schema::hasTable('freelance_deliverables')) {
            Schema::table('freelance_deliverables', function (Blueprint $table) {
                if (!Schema::hasColumn('freelance_deliverables', 'milestone_id')) {
                    $table->foreignId('milestone_id')->nullable()->after('project_id')->constrained('freelance_project_milestones')->nullOnDelete();
                }
                if (!Schema::hasColumn('freelance_deliverables', 'status')) {
                    $table->string('status', 20)->default('submitted')->after('is_final');
                }
            });
        }

        // 15. Extend Freelancer Profiles (Feature 10)
        if (Schema::hasTable('freelancer_profiles')) {
            Schema::table('freelancer_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('freelancer_profiles', 'response_time_seconds')) {
                    $table->unsignedInteger('response_time_seconds')->nullable()->after('response_time');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('freelance_messages')) {
            Schema::table('freelance_messages', function (Blueprint $table) {
                if (Schema::hasColumn('freelance_messages', 'deliverable_id')) {
                    $table->dropForeign(['deliverable_id']);
                    $table->dropColumn('deliverable_id');
                }
                if (Schema::hasColumn('freelance_messages', 'milestone_id')) {
                    $table->dropForeign(['milestone_id']);
                    $table->dropColumn('milestone_id');
                }
                if (Schema::hasColumn('freelance_messages', 'is_review_request')) {
                    $table->dropColumn('is_review_request');
                }
            });
        }

        if (Schema::hasTable('freelance_deliverables')) {
            Schema::table('freelance_deliverables', function (Blueprint $table) {
                if (Schema::hasColumn('freelance_deliverables', 'milestone_id')) {
                    $table->dropForeign(['milestone_id']);
                    $table->dropColumn('milestone_id');
                }
                if (Schema::hasColumn('freelance_deliverables', 'status')) {
                    $table->dropColumn('status');
                }
            });
        }

        Schema::dropIfExists('freelancer_profile_media');
        Schema::dropIfExists('freelance_deliverable_comments');
        Schema::dropIfExists('freelance_review_replies');
        Schema::dropIfExists('communication_moderation_records');
        Schema::dropIfExists('freelancer_unavailabilities');
        Schema::dropIfExists('freelance_project_milestones');
        Schema::dropIfExists('freelance_category_requests');
        Schema::dropIfExists('freelance_categories');
    }
};
