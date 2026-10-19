<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationModerationRecord;
use App\Models\FreelanceCategory;
use App\Models\FreelanceCategoryRequest;
use App\Models\FreelanceProject;
use App\Models\FreelanceProjectMilestone;
use App\Models\FreelancerProfile;
use App\Models\FreelancerProfileMedia;
use App\Models\FreelancerService;
use App\Models\FreelancerWallet;
use App\Models\FreelancerWalletTransaction;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminFreelanceModerationController extends Controller
{
    // ==========================================
    // 1. Category Management (Feature 2 & 5)
    // ==========================================

    public function categories(Request $request): JsonResponse
    {
        $query = FreelanceCategory::with(['children', 'parent:id,name'])->orderBy('sort_order')->orderBy('name');
        if ($request->filled('type')) {
            $type = $request->query('type');
            $query->where(fn ($q) => $q->where('type', $type)->orWhere('type', 'both'));
        }
        return $this->successResponse($query->get(), 'Freelance categories fetched successfully.');
    }

    public function allCategoriesFlat(): JsonResponse
    {
        return $this->successResponse(
            FreelanceCategory::with('parent:id,name')->orderBy('name')->get(),
            'All categories fetched successfully.'
        );
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|exists:freelance_categories,id',
            'type' => 'required|in:service,portfolio,both',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $slug = Str::slug($validated['name']);
        $count = FreelanceCategory::where('slug', 'like', "{$slug}%")->count();
        if ($count > 0) $slug .= '-' . ($count + 1);

        $category = FreelanceCategory::create([
            ...$validated,
            'slug' => $slug,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->successResponse($category, 'Category created successfully.', 201);
    }

    public function updateCategory(Request $request, FreelanceCategory $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|different:id|exists:freelance_categories,id',
            'type' => 'required|in:service,portfolio,both',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $category->update($validated);
        return $this->successResponse($category->fresh('children'), 'Category updated successfully.');
    }

    public function toggleCategoryStatus(FreelanceCategory $category): JsonResponse
    {
        $category->update(['is_active' => !$category->is_active]);
        return $this->successResponse($category, 'Category status toggled successfully.');
    }

    // ==========================================
    // 2. Category Requests (Feature 2 & 5)
    // ==========================================

    public function categoryRequests(Request $request): JsonResponse
    {
        $query = FreelanceCategoryRequest::with(['user:id,name,email', 'freelancerProfile:id,professional_title', 'parentCategory:id,name'])->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        return $this->paginatedResponse($query->paginate(20), 'Category requests fetched successfully.');
    }

    public function approveCategoryRequest(Request $request, FreelanceCategoryRequest $categoryRequest): JsonResponse
    {
        abort_unless($categoryRequest->status === 'pending', 422, 'Request is already processed.');

        $category = DB::transaction(function () use ($categoryRequest, $request) {
            $slug = Str::slug($categoryRequest->name);
            $count = FreelanceCategory::where('slug', 'like', "{$slug}%")->count();
            if ($count > 0) $slug .= '-' . ($count + 1);

            $createdCategory = FreelanceCategory::create([
                'name' => $categoryRequest->name,
                'slug' => $slug,
                'type' => $categoryRequest->type === 'portfolio' ? 'portfolio' : 'service',
                'parent_id' => $categoryRequest->parent_id,
                'description' => $categoryRequest->suggested_description,
                'is_active' => true,
            ]);

            $categoryRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'created_category_id' => $createdCategory->id,
                'admin_feedback' => $request->input('admin_feedback', 'Category approved and published.'),
            ]);

            return $createdCategory;
        });

        return $this->successResponse([
            'request' => $categoryRequest->fresh(),
            'category' => $category,
        ], 'Category request approved and published successfully.');
    }

    public function rejectCategoryRequest(Request $request, FreelanceCategoryRequest $categoryRequest): JsonResponse
    {
        abort_unless($categoryRequest->status === 'pending', 422, 'Request is already processed.');
        $validated = $request->validate([
            'admin_feedback' => 'required|string|max:1000',
        ]);

        $categoryRequest->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_feedback' => $validated['admin_feedback'],
        ]);

        return $this->successResponse($categoryRequest->fresh(), 'Category request rejected.');
    }

    // ==========================================
    // 3. Service Moderation (Feature 4 & 8)
    // ==========================================

    public function services(Request $request): JsonResponse
    {
        $query = FreelancerService::with(['freelancerProfile.user:id,name,email,avatar', 'packages', 'categoryModel'])->latest();
        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', $request->query('moderation_status'));
        }
        return $this->paginatedResponse($query->paginate(20), 'Services fetched for moderation.');
    }

    public function approveService(Request $request, FreelancerService $service): JsonResponse
    {
        $service->update([
            'moderation_status' => 'approved',
            'status' => 'active',
            'video_status' => 'approved',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'moderation_feedback' => null,
        ]);
        return $this->successResponse($service->fresh(), 'Service approved and activated successfully.');
    }

    public function rejectService(Request $request, FreelancerService $service): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:2000']);
        $service->update([
            'moderation_status' => 'rejected',
            'status' => 'rejected',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'moderation_feedback' => $validated['reason'],
        ]);
        return $this->successResponse($service->fresh(), 'Service rejected.');
    }

    public function moderateService(Request $request, FreelancerService $service): JsonResponse
    {
        $decision = $request->input('decision');
        if ($decision === 'approved') return $this->approveService($request, $service);
        if ($decision === 'rejected') return $this->rejectService($request, $service);
        if ($decision === 'changes_requested') return $this->requestChangesService($request, $service);
        return $this->errorResponse('Invalid moderation decision.', 422);
    }

    public function requestChangesService(Request $request, FreelancerService $service): JsonResponse
    {
        $feedback = $request->input('feedback', $request->input('reason', 'Changes requested by admin.'));
        $service->update([
            'moderation_status' => 'changes_requested',
            'status' => 'draft',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'moderation_feedback' => $feedback,
        ]);
        return $this->successResponse($service->fresh(), 'Changes requested on service.');
    }

    // ==========================================
    // 4. Portfolio Moderation (Feature 6 & 8)
    // ==========================================

    public function portfolios(Request $request): JsonResponse
    {
        $query = PortfolioItem::with(['freelancerProfile.user:id,name,email,avatar', 'media', 'categoryModel'])->latest();
        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', $request->query('moderation_status'));
        }
        return $this->paginatedResponse($query->paginate(20), 'Portfolios fetched for moderation.');
    }

    public function approvePortfolio(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $portfolioItem->update([
            'moderation_status' => 'approved',
            'status' => 'published',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'moderation_feedback' => null,
        ]);
        $portfolioItem->media()->update(['moderation_status' => 'approved']);
        return $this->successResponse($portfolioItem->fresh('media'), 'Portfolio item approved.');
    }

    public function rejectPortfolio(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $portfolioItem->update([
            'moderation_status' => 'rejected',
            'status' => 'rejected',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'moderation_feedback' => $request->input('reason', $request->input('feedback', 'Rejected by admin.')),
        ]);
        return $this->successResponse($portfolioItem->fresh('media'), 'Portfolio item rejected.');
    }

    public function moderatePortfolio(Request $request, PortfolioItem $portfolio): JsonResponse
    {
        $decision = $request->input('decision');
        if ($decision === 'approved') return $this->approvePortfolio($request, $portfolio);
        if ($decision === 'rejected') return $this->rejectPortfolio($request, $portfolio);
        if ($decision === 'changes_requested') {
            $portfolio->update([
                'moderation_status' => 'changes_requested',
                'status' => 'draft',
                'moderated_by' => $request->user()->id,
                'moderated_at' => now(),
                'moderation_feedback' => $request->input('feedback', 'Changes requested by admin.'),
            ]);
            return $this->successResponse($portfolio->fresh('media'), 'Changes requested on portfolio item.');
        }
        return $this->errorResponse('Invalid moderation decision.', 422);
    }

    // ==========================================
    // 5. Communication Moderation Queue (Feature 7)
    // ==========================================

    public function communicationQueue(Request $request): JsonResponse
    {
        $query = CommunicationModerationRecord::with('user:id,name,email')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        return $this->paginatedResponse($query->paginate(25), 'Communication moderation queue fetched.');
    }

    public function dismissCommunicationRecord(CommunicationModerationRecord $record): JsonResponse
    {
        $record->update(['status' => 'dismissed']);
        return $this->successResponse($record, 'Flagged record dismissed.');
    }

    // ==========================================
    // 6. Milestones & Financial Oversight (Feature 1)
    // ==========================================

    public function milestones(Request $request): JsonResponse
    {
        $query = FreelanceProjectMilestone::with(['project.client:id,name', 'project.freelancerProfile.user:id,name'])->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        return $this->paginatedResponse($query->paginate(25), 'Project milestones fetched.');
    }

    public function walletAudit(Request $request): JsonResponse
    {
        $query = FreelancerWalletTransaction::with(['wallet.freelancerProfile.user:id,name,email', 'project:id,title,reference'])->latest();
        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        return $this->paginatedResponse($query->paginate(30), 'Wallet transactions audit fetched.');
    }

    public function overviewStats(): JsonResponse
    {
        return $this->successResponse([
            'pending_services' => FreelancerService::where('moderation_status', 'pending_review')->count(),
            'pending_portfolios' => PortfolioItem::where('moderation_status', 'pending_review')->count(),
            'pending_category_requests' => FreelanceCategoryRequest::where('status', 'pending')->count(),
            'flagged_communications' => CommunicationModerationRecord::where('status', 'flagged')->count(),
            'active_projects' => FreelanceProject::whereIn('status', ['payment_pending', 'in_progress', 'submitted', 'revision_requested'])->count(),
            'pending_withdrawals' => DB::table('freelancer_withdrawals')->where('status', 'pending')->count(),
            'total_milestones_released' => FreelanceProjectMilestone::where('status', 'released')->count(),
            'total_volume_held' => FreelanceProject::whereIn('status', ['in_progress', 'submitted', 'revision_requested'])->sum('held_amount'),
            'total_volume_released' => FreelanceProject::sum('released_amount'),
        ], 'Overview stats fetched successfully.');
    }
}
