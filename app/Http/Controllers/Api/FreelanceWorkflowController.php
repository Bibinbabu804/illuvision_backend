<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\FreelanceConversation;
use App\Models\FreelanceConversationParticipant;
use App\Models\FreelanceDeliverable;
use App\Models\FreelanceDeliverableComment;
use App\Models\FreelanceMessage;
use App\Models\FreelanceMessageAttachment;
use App\Models\FreelanceNotification;
use App\Models\FreelancePayment;
use App\Models\FreelanceProject;
use App\Models\FreelanceProjectFile;
use App\Models\FreelanceProjectMilestone;
use App\Models\FreelanceProposal;
use App\Models\FreelanceReview;
use App\Models\FreelanceReviewReply;
use App\Models\FreelancerProfile;
use App\Models\FreelancerService;
use App\Models\FreelancerServicePackage;
use App\Models\FreelancerWallet;
use App\Models\FreelancerWalletTransaction;
use App\Models\FreelancerWithdrawal;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Domain\Payment\Support\Money;
use App\Services\Freelance\ContentSafetyService;
use App\Services\Freelance\MilestonePaymentService;
use App\Services\Freelance\ResponseTimeCalculatorService;
use App\Services\Freelance\ServicePricingEstimatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreelanceWorkflowController extends Controller
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected StorageContractInterface $storage,
        protected MilestonePaymentService $milestonePaymentService,
        protected ContentSafetyService $safety,
        protected ResponseTimeCalculatorService $responseTimeService,
        protected ServicePricingEstimatorService $pricingEstimator,
    ) {}

    public function projects(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = FreelanceProject::with([
            'service',
            'client:id,name,avatar',
            'freelancerProfile.user:id,name,avatar',
            'milestones',
        ])->latest();
        $query->where(fn ($q) => $q->where('client_id', $user->id)->orWhereHas('freelancerProfile', fn ($p) => $p->where('user_id', $user->id)));

        if ($request->filled('status')) {
            $status = $request->query('status');
            if ($status === 'active') {
                $query->whereIn('status', ['requested', 'freelancer_reviewing', 'proposal_sent', 'payment_pending', 'in_progress', 'submitted', 'revision_requested', 'disputed']);
            } elseif ($status === 'completed') {
                $query->where('status', 'completed');
            } elseif ($status === 'requests') {
                $query->whereIn('status', ['requested', 'freelancer_reviewing']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('reference', 'like', $term));
        }

        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Projects fetched successfully.');
    }

    public function dashboard(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        $wallet = FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $profile->id]);
        $projects = FreelanceProject::with([
            'client:id,name,avatar',
            'service:id,title',
            'payments:id,project_id,status,amount',
            'deliverables:id,project_id,title,submitted_at',
            'milestones',
        ])->where('freelancer_profile_id', $profile->id)->latest()->get();

        $conversations = FreelanceConversation::with([
            'project:id,title,status',
            'participants.user:id,name,avatar',
            'messages' => fn ($q) => $q->latest()->limit(1),
        ])->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))->latest('last_message_at')->limit(5)->get();

        $proposals = FreelanceProposal::with('project.client:id,name,avatar')->where('freelancer_profile_id', $profile->id)->latest()->limit(10)->get();

        $reviews = FreelanceReview::with(['client:id,name,avatar', 'project:id,title,reference', 'reply'])
            ->where('freelancer_profile_id', $profile->id)->latest()->limit(5)->get();

        return $this->successResponse([
            'profile' => new \App\Http\Resources\FreelancerProfileResource(
                $profile->load('user:id,name,avatar', 'skills', 'services.packages', 'portfolioItems.media', 'profileMedia', 'unavailabilities')
            ),
            'wallet' => $wallet,
            'projects' => $projects,
            'proposals' => $proposals,
            'conversations' => $conversations,
            'reviews' => $reviews,
            'notifications' => $request->user()->freelanceNotifications()->latest()->limit(8)->get(),
            'metrics' => [
                'active_projects' => $projects->whereIn('status', ['requested', 'freelancer_reviewing', 'proposal_sent', 'payment_pending', 'in_progress', 'submitted', 'revision_requested', 'disputed'])->count(),
                'completed_projects' => $projects->where('status', 'completed')->count(),
                'pending_proposals' => $proposals->where('status', 'pending')->count(),
                'pending_requests' => $projects->whereIn('status', ['requested', 'freelancer_reviewing'])->count(),
                'unread_messages' => $conversations->sum(fn ($conversation) => $conversation->messages->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->count()),
                'available_balance' => (float) ($wallet->available_balance ?? 0),
                'pending_balance' => (float) ($wallet->pending_balance ?? 0),
                'lifetime_earnings' => (float) ($wallet->lifetime_earnings ?? $profile->total_earnings ?? 0),
                'rating' => (float) $profile->rating,
                'total_reviews' => (int) $profile->total_reviews,
                'response_time_text' => ResponseTimeCalculatorService::format($profile->response_time_seconds),
            ],
        ], 'Freelancer dashboard fetched successfully.');
    }

    public function notifications(Request $request): JsonResponse
    {
        $query = $request->user()->freelanceNotifications()->latest();
        if ($request->boolean('unread')) $query->whereNull('read_at');
        return $this->paginatedResponse($query->paginate(20), 'Freelancer notifications fetched successfully.');
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $request->user()->freelanceNotifications()->whereNull('read_at')->update(['read_at' => now()]);
        return $this->successResponse(null, 'All notifications marked as read.');
    }

    public function markNotificationRead(Request $request, FreelanceNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);
        return $this->successResponse($notification, 'Notification marked as read.');
    }

    public function reviews(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        $query = FreelanceReview::with(['client:id,name,avatar', 'project:id,title,reference', 'reply'])
            ->where('freelancer_profile_id', $profile->id)->latest();
        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Freelancer reviews fetched successfully.');
    }

    public function proposals(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        $query = FreelanceProposal::with('project.client:id,name,avatar', 'project.service:id,title')->where('freelancer_profile_id', $profile->id)->latest();
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        return $this->paginatedResponse($query->paginate(min((int) $request->query('per_page', 15), 50)), 'Freelancer proposals fetched successfully.');
    }

    public function showProject(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        return $this->successResponse($project->load([
            'service',
            'package',
            'client:id,name,avatar',
            'freelancerProfile.user:id,name,avatar',
            'proposals',
            'deliverables.submitter:id,name',
            'deliverables.comments.user:id,name,avatar',
            'files.uploader:id,name',
            'payments',
            'review.reply',
            'milestones.deliverable.comments.user:id,name,avatar',
            'milestones.deliverable.submitter:id,name',
        ]), 'Project fetched successfully.');
    }

    public function projectFiles(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        return $this->successResponse($project->files()->with('uploader:id,name')->latest()->get(), 'Project files fetched successfully.');
    }

    public function createProject(Request $request): JsonResponse
    {
        $this->ensureRole($request, 'client');
        $data = $request->validate([
            'service_id' => 'required|exists:freelancer_services,id',
            'package_id' => 'required|exists:freelancer_service_packages,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'quantity' => 'nullable|integer|min:1|max:10',
            'extra_revisions' => 'nullable|integer|min:0|max:20',
            'express_delivery' => 'nullable|boolean',
            'custom_addons' => 'nullable|array',
            'custom_addons.*.name' => 'required|string|max:100',
            'custom_addons.*.price' => 'required|numeric|min:0',
        ]);

        $service = FreelancerService::with(['packages', 'freelancerProfile'])->where('status', 'active')->findOrFail($data['service_id']);

        // FEATURE 3: Prevent ordering paused services
        if ($service->is_paused) {
            return $this->errorResponse('This service is temporarily unavailable and cannot be ordered right now.', 422);
        }

        // FEATURE 9: Check freelancer unavailable date range
        $activeUnavail = $service->freelancerProfile->activeUnavailability();
        if ($activeUnavail) {
            $until = $activeUnavail->to_date->format('d M Y');
            return $this->errorResponse("This freelancer is unavailable until {$until}. Please choose another service or contact them later.", 422);
        }

        $package = $service->packages()->findOrFail($data['package_id']);

        // FEATURE 11: Service price estimator & immutable pricing snapshot
        $pricingSnapshot = $this->pricingEstimator->estimate($service, [
            'package_id' => $package->id,
            'quantity' => $data['quantity'] ?? 1,
            'extra_revisions' => $data['extra_revisions'] ?? 0,
            'express_delivery' => $data['express_delivery'] ?? false,
            'custom_addons' => $data['custom_addons'] ?? [],
        ]);

        $project = DB::transaction(function () use ($request, $service, $package, $data, $pricingSnapshot) {
            $grossPaise = Money::toPaise($pricingSnapshot['total_amount']);
            $feePaise = Money::toPaise($pricingSnapshot['breakdown']['platform_fee'] ?? round(Money::toRupees($grossPaise) * 0.10, 2));
            $freelancerPaise = $grossPaise - $feePaise;

            $deliveryDays = $package->delivery_days;
            if (!empty($data['express_delivery']) && $deliveryDays > 1) {
                $deliveryDays = max(1, (int) ceil($deliveryDays / 2));
            }

            $project = FreelanceProject::create([
                'client_id' => $request->user()->id,
                'freelancer_profile_id' => $service->freelancer_profile_id,
                'freelancer_service_id' => $service->id,
                'freelancer_service_package_id' => $package->id,
                'reference' => 'PRJ-'.strtoupper(Str::random(10)),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'requested',
                'gross_amount' => Money::toRupees($grossPaise),
                'platform_fee' => Money::toRupees($feePaise),
                'freelancer_amount' => Money::toRupees($freelancerPaise),
                'delivery_days' => $deliveryDays,
                'revision_limit' => ($package->revisions ?? 1) + ($data['extra_revisions'] ?? 0),
                'milestone_advance_pct' => 30,
                'milestone_final_pct' => 70,
                'held_amount' => 0.00,
                'released_amount' => 0.00,
                'pricing_snapshot' => $pricingSnapshot,
            ]);

            // FEATURE 1: Initialize 30% advance & 70% final milestones
            $this->milestonePaymentService->initializeMilestonesForProject($project);

            $conversation = FreelanceConversation::create([
                'project_id' => $project->id,
                'freelancer_service_id' => $service->id,
            ]);
            $uniqueParticipants = array_unique([$request->user()->id, $service->freelancerProfile->user_id]);
            foreach ($uniqueParticipants as $pUserId) {
                $conversation->participants()->create(['user_id' => $pUserId]);
            }

            return $project;
        });

        $this->createNotification($service->freelancerProfile->user_id, 'project', 'New project request', "A client requested {$project->title}.", ['project_id' => $project->id]);

        return $this->successResponse($project->load('service', 'freelancerProfile.user', 'conversation', 'milestones'), 'Project request created successfully.', 201);
    }

    public function sendProposal(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        abort_unless($project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless(in_array($project->status, ['requested', 'freelancer_reviewing']), 422);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'delivery_days' => 'required|integer|min:1|max:3650',
            'revisions' => 'nullable|integer|min:0|max:100',
            'description' => 'nullable|string|max:10000',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $proposal = DB::transaction(function () use ($project, $data) {
            $proposal = $project->proposals()->updateOrCreate(
                ['freelancer_profile_id' => $project->freelancer_profile_id],
                [...$data, 'status' => 'pending']
            );
            $this->transitionProject($project, 'proposal_sent');
            return $proposal;
        });

        $this->createNotification($project->client_id, 'proposal', 'New proposal received', "A freelancer sent a proposal for {$project->title}.", ['project_id' => $project->id, 'proposal_id' => $proposal->id]);
        return $this->successResponse($proposal, 'Proposal sent successfully.', 201);
    }

    public function acceptProposal(Request $request, FreelanceProposal $proposal): JsonResponse
    {
        $this->ensureRole($request, 'client');
        $project = $proposal->project;
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($proposal->status === 'pending', 422);

        DB::transaction(function () use ($proposal, $project) {
            $proposal->update(['status' => 'accepted']);
            $amountPaise = Money::toPaise($proposal->amount);
            $feePaise = (int) round($amountPaise * 0.10);
            $this->transitionProject($project, 'payment_pending');
            $project->update([
                'gross_amount' => Money::toRupees($amountPaise),
                'platform_fee' => Money::toRupees($feePaise),
                'freelancer_amount' => Money::toRupees($amountPaise - $feePaise),
                'delivery_days' => $proposal->delivery_days,
                'revision_limit' => $proposal->revisions,
            ]);
            $project->proposals()->where('id', '!=', $proposal->id)->update(['status' => 'rejected']);

            // Re-sync milestones with updated proposal amount
            $this->milestonePaymentService->initializeMilestonesForProject($project);
        });

        return $this->successResponse($project->fresh('milestones'), 'Proposal accepted. Payment is now required.');
    }

    public function conversations(Request $request): JsonResponse
    {
        $items = FreelanceConversation::with('participants.user:id,name,avatar')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest('last_message_at')
            ->paginate(20);
        return $this->paginatedResponse($items, 'Conversations fetched successfully.');
    }

    public function messages(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);
        return $this->paginatedResponse(
            $conversation->messages()
                ->with(['sender:id,name,avatar', 'attachments', 'milestone', 'deliverable'])
                ->latest()
                ->paginate(30),
            'Messages fetched successfully.'
        );
    }

    public function markConversationRead(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);
        $conversation->participants()->where('user_id', $request->user()->id)->update(['last_read_at' => now()]);
        $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->update(['read_at' => now()]);
        return $this->successResponse(null, 'Conversation marked as read.');
    }

    // FEATURE 7 & 12: Controlled Communication & Chat Attachments / Review Submissions
    public function sendMessage(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);

        $data = $request->validate([
            'body' => 'nullable|string|max:20000',
            'message_type' => 'nullable|in:text,image,video,audio,file,offer',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
            'is_review_request' => 'nullable|boolean',
            'milestone_id' => 'nullable|exists:freelance_project_milestones,id',
            'deliverable_id' => 'nullable|exists:freelance_deliverables,id',
        ]);

        abort_if(empty($data['body']) && ! $request->hasFile('file') && ! $request->hasFile('files'), 422, 'A message or attachment is required.');

        // FEATURE 7: Content Safety Scan
        if (!empty($data['body'])) {
            $safetyCheck = $this->safety->scanText($data['body']);
            if ($safetyCheck['has_violations']) {
                $this->safety->recordViolation(
                    $request->user()->id,
                    'chat_message',
                    $data['body'],
                    $safetyCheck['violations'],
                    $safetyCheck['detected_patterns']
                );
                return $this->errorResponse($safetyCheck['friendly_warning'], 422, ['violations' => $safetyCheck['violations']]);
            }
        }

        $message = DB::transaction(function () use ($request, $conversation, $data) {
            $message = $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'message_type' => $data['message_type'] ?? 'text',
                'body' => $data['body'] ?? null,
                'is_review_request' => (bool) ($data['is_review_request'] ?? false),
                'milestone_id' => $data['milestone_id'] ?? null,
                'deliverable_id' => $data['deliverable_id'] ?? null,
            ]);

            // Handle multiple files
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $path = $this->storage->store($file, "freelancing/conversations/{$conversation->id}");
                    $message->attachments()->create([
                        'uploaded_by' => $request->user()->id,
                        'original_name' => $file->getClientOriginalName(),
                        'path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }
            } elseif ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $this->storage->store($file, "freelancing/conversations/{$conversation->id}");
                $message->attachments()->create([
                    'uploaded_by' => $request->user()->id,
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            $conversation->update(['last_message_at' => now()]);
            return $message;
        });

        // FEATURE 10: Automatic response time tracking
        $freelancerProfile = FreelancerProfile::where('user_id', $request->user()->id)->first();
        if ($freelancerProfile) {
            $this->responseTimeService->recordReply($freelancerProfile, $conversation, $message);
        }

        $conversation->participants()->where('user_id', '!=', $request->user()->id)->pluck('user_id')->each(function ($userId) use ($conversation, $message) {
            $title = $message->is_review_request ? 'Deliverable Review Request' : 'New message';
            $this->createNotification((int) $userId, 'message', $title, 'You have an update in your project conversation.', [
                'conversation_id' => $conversation->id,
                'project_id' => $conversation->project_id,
            ]);
        });

        return $this->successResponse($message->load('sender:id,name,avatar', 'attachments', 'milestone', 'deliverable'), 'Message sent successfully.', 201);
    }

    // FEATURE 1: Milestone Deliverable Submission
    public function submitMilestoneDeliverable(Request $request, FreelanceProject $project, FreelanceProjectMilestone $milestone): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        abort_unless($project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'nullable|string|max:10000',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
        ]);

        $uploadedFiles = [];
        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $f) {
                $p = $this->storage->store($f, "freelancing/projects/{$project->id}/deliverables");
                $uploadedFiles[] = ['name' => $f->getClientOriginalName(), 'path' => $p, 'mime' => $f->getMimeType(), 'size' => $f->getSize()];
            }
        } elseif ($request->hasFile('file')) {
            $f = $request->file('file');
            $p = $this->storage->store($f, "freelancing/projects/{$project->id}/deliverables");
            $uploadedFiles[] = ['name' => $f->getClientOriginalName(), 'path' => $p, 'mime' => $f->getMimeType(), 'size' => $f->getSize()];
        }

        $deliverable = $this->milestonePaymentService->submitMilestoneDeliverable($project, $milestone, [
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'message' => $data['message'] ?? '',
            'files' => $uploadedFiles,
        ]);

        // Post review request card to chat
        if ($conversation = $project->conversation) {
            $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'message_type' => 'file',
                'body' => "Submitted deliverable for Milestone #{$milestone->milestone_number} ({$milestone->title}): " . $data['title'],
                'is_review_request' => true,
                'milestone_id' => $milestone->id,
                'deliverable_id' => $deliverable->id,
            ]);
            $conversation->update(['last_message_at' => now()]);
        }

        $this->createNotification($project->client_id, 'project', "Milestone #{$milestone->milestone_number} submitted", "Deliverable submitted for {$milestone->title}. Please review and approve.", [
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
        ]);

        return $this->successResponse($deliverable->load('milestone'), 'Milestone deliverable submitted for review.', 201);
    }

    // FEATURE 1: Client Milestone Approval with Wallet Release
    public function approveMilestone(Request $request, FreelanceProject $project, FreelanceProjectMilestone $milestone): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($milestone->project_id === $project->id, 404);

        $result = $this->milestonePaymentService->approveMilestone($project, $milestone, $request->user());

        $this->createNotification(
            $project->freelancerProfile->user_id,
            'milestone_approved',
            "Milestone #{$milestone->milestone_number} Approved",
            "Client approved {$milestone->title}! ₹" . number_format($milestone->freelancer_net_amount, 2) . " has been released to your wallet.",
            ['project_id' => $project->id, 'milestone_id' => $milestone->id]
        );

        return $this->successResponse($result, 'Milestone approved and funds released to freelancer wallet successfully.');
    }

    // FEATURE 1: Milestone Revision Request
    public function requestMilestoneRevision(Request $request, FreelanceProject $project, FreelanceProjectMilestone $milestone): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate(['notes' => 'required|string|max:5000']);

        $updatedMilestone = $this->milestonePaymentService->requestMilestoneRevision($project, $milestone, $data['notes']);

        if ($conversation = $project->conversation) {
            $conversation->messages()->create([
                'sender_id' => $request->user()->id,
                'message_type' => 'text',
                'body' => "Revision requested for Milestone #{$milestone->milestone_number} ({$milestone->title}): " . $data['notes'],
            ]);
            $conversation->update(['last_message_at' => now()]);
        }

        $this->createNotification(
            $project->freelancerProfile->user_id,
            'revision_requested',
            "Revision requested for Milestone #{$milestone->milestone_number}",
            $data['notes'],
            ['project_id' => $project->id, 'milestone_id' => $milestone->id]
        );

        return $this->successResponse($updatedMilestone, 'Milestone revision requested.');
    }

    // Existing generic submission preserved for compatibility
    public function submitDeliverable(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        abort_unless($project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless(in_array($project->status, ['in_progress', 'revision_requested']), 422);

        $milestone = $project->milestones()->whereIn('status', ['pending', 'in_progress', 'revision_requested'])->orderBy('milestone_number')->first();
        if ($milestone) {
            return $this->submitMilestoneDeliverable($request, $project, $milestone);
        }

        $data = $request->validate(['title' => 'required|string|max:255', 'message' => 'nullable|string|max:10000', 'is_final' => 'boolean']);
        $deliverable = DB::transaction(function () use ($project, $request, $data) {
            $deliverable = $project->deliverables()->create([
                ...$data,
                'submitted_by' => $request->user()->id,
                'revision_number' => $project->revision_count + 1,
                'submitted_at' => now(),
            ]);
            $this->transitionProject($project, 'submitted');
            return $deliverable;
        });

        $this->createNotification($project->client_id, 'project', 'Delivery submitted', "New work was submitted for {$project->title}.", ['project_id' => $project->id]);
        return $this->successResponse($deliverable, 'Work submitted successfully.', 201);
    }

    public function requestRevision(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($project->status === 'submitted', 422);
        abort_if($project->revision_count >= $project->revision_limit, 422, 'The revision limit for this project has been reached.');
        $data = $request->validate(['message' => 'required|string|max:10000']);
        $this->transitionProject($project, 'revision_requested');
        $project->update(['revision_count' => DB::raw('revision_count + 1')]);
        if ($conversation = $project->conversation) {
            $conversation->messages()->create(['sender_id' => $request->user()->id, 'message_type' => 'text', 'body' => 'Revision requested: '.$data['message']]);
            $conversation->update(['last_message_at' => now()]);
        }
        return $this->successResponse($project->fresh(), 'Revision requested successfully.');
    }

    public function cancelProject(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        abort_unless(in_array($project->status, ['requested', 'freelancer_reviewing', 'proposal_sent', 'payment_pending', 'in_progress'], true), 422, 'This project cannot be cancelled at its current stage.');
        $data = $request->validate(['reason' => 'required|string|max:5000']);
        $payment = $project->payments()->where('status', 'held')->latest()->first();
        if ($payment?->gateway_payment_id) {
            $this->gateway->refund($payment->gateway_payment_id, Money::toPaise($payment->amount), ['reference' => $project->reference]);
        }
        DB::transaction(function () use ($project, $data, $payment) {
            $this->transitionProject($project, 'cancelled');
            $project->update(['cancellation_reason' => $data['reason']]);
            if ($payment) $payment->update(['status' => 'refunded', 'refunded_at' => now()]);
        });
        return $this->successResponse($project->fresh('payments'), 'Project cancelled successfully.');
    }

    public function disputeProject(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        abort_unless(in_array($project->status, ['in_progress', 'submitted', 'revision_requested'], true), 422, 'This project cannot be disputed at its current stage.');
        $data = $request->validate(['reason' => 'required|string|max:5000']);
        $this->transitionProject($project, 'disputed');
        $project->update(['dispute_reason' => $data['reason'], 'disputed_at' => now()]);
        return $this->successResponse($project->fresh(), 'Project dispute opened successfully.');
    }

    public function uploadProjectFile(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200',
            'purpose' => 'nullable|in:requirement,deliverable',
        ]);
        $file = $request->file('file');
        $path = $this->storage->store($file, "freelancing/projects/{$project->id}");
        $record = $project->files()->create([
            'uploaded_by' => $request->user()->id,
            'purpose' => $request->input('purpose', 'requirement'),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
        return $this->successResponse(['id' => $record->id, 'name' => $record->original_name, 'purpose' => $record->purpose, 'url' => asset('storage/'.$record->path)], 'Project file uploaded successfully.', 201);
    }

    public function approveProject(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);

        // If project has final milestone pending, approve it using milestone payment service
        $finalMilestone = $project->milestones()->where('is_final', true)->first();
        if ($finalMilestone && $finalMilestone->status !== 'approved') {
            return $this->approveMilestone($request, $project, $finalMilestone);
        }

        $project = DB::transaction(function () use ($project) {
            $project = FreelanceProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($project->status === 'submitted' || $project->status === 'in_progress', 422);
            $heldPayment = $project->payments()->where('status', 'held')->lockForUpdate()->latest()->first();
            abort_unless($heldPayment, 422, 'A verified held payment is required before approval.');
            $this->transitionProject($project, 'completed');
            $project->update(['completed_at' => now()]);
            $payment = $heldPayment;
            if ($payment) {
                $payment->update(['status' => 'released', 'released_at' => now()]);
                $wallet = FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $project->freelancer_profile_id]);
                $wallet->refresh();
                $wallet->available_balance = Money::toRupees(Money::toPaise($wallet->available_balance) + Money::toPaise($project->freelancer_amount));
                $wallet->lifetime_earnings = Money::toRupees(Money::toPaise($wallet->lifetime_earnings) + Money::toPaise($project->freelancer_amount));
                $wallet->save();
                $wallet->transactions()->create([
                    'project_id' => $project->id,
                    'type' => 'project_payment',
                    'amount' => $project->freelancer_amount,
                    'balance_after' => $wallet->available_balance,
                    'reference' => 'WAL-'.strtoupper(Str::random(12)),
                    'description' => 'Project payment released',
                ]);
            }
            return $project;
        });

        return $this->successResponse($project->fresh('payments'), 'Project completed successfully.');
    }

    public function createPaymentOrder(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($project->status === 'payment_pending', 422);

        $payment = $project->payments()->whereIn('status', ['pending', 'held'])->latest()->first();
        if ($payment?->gateway_order_id) return $this->successResponse($payment, 'Existing payment order fetched.');

        $order = $this->gateway->createOrder([
            'amount' => Money::toPaise($project->gross_amount),
            'currency' => Money::GATEWAY_CURRENCY,
            'receipt' => $project->reference,
        ]);

        $payment = $project->payments()->create([
            'client_id' => $request->user()->id,
            'gateway_order_id' => $order['id'] ?? null,
            'amount' => $project->gross_amount,
            'status' => 'pending',
            'metadata' => $order,
        ]);

        return $this->successResponse(['payment' => $payment, 'gateway_order' => $order], 'Payment order created successfully.', 201);
    }

    public function verifyPayment(Request $request, FreelancePayment $payment): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($payment->client_id === $request->user()->id, 403);

        if (in_array($payment->status, ['held', 'released'], true)) {
            return $this->successResponse($payment->load('project'), 'Payment was already verified.');
        }

        $data = $request->validate([
            'gateway_payment_id' => 'required|string',
            'gateway_signature' => 'required|string',
        ]);

        abort_unless($this->gateway->verifySignature($payment->gateway_order_id, $data['gateway_payment_id'], $data['gateway_signature']), 422, 'Payment signature verification failed.');

        DB::transaction(function () use ($payment, $data) {
            $payment = FreelancePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, ['held', 'released'], true)) return;

            $payment->update([...$data, 'status' => 'held', 'held_at' => now()]);
            $project = $payment->project()->lockForUpdate()->firstOrFail();

            if ($project->status !== 'in_progress') {
                $this->transitionProject($project, 'in_progress');
            }

            // Record held funds and initialize milestones
            $this->milestonePaymentService->fundProjectPayment($project, $payment->amount);

            FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $payment->project->freelancer_profile_id]);
        });

        $this->createNotification(
            $payment->project->freelancerProfile->user_id,
            'payment',
            'Payment funded',
            "Payment for {$payment->project->title} is held and work can begin.",
            ['project_id' => $payment->project_id, 'payment_id' => $payment->id]
        );

        return $this->successResponse($payment->fresh('project'), 'Payment verified and funds held successfully.');
    }

    public function createReview(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($project->status === 'completed' && ! $project->review()->exists(), 422);

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'review' => 'nullable|string|max:5000',
        ]);

        // FEATURE 7: Content safety scan on review
        if (!empty($data['review'])) {
            $safetyCheck = $this->safety->scanText($data['review']);
            if ($safetyCheck['has_violations']) {
                $this->safety->recordViolation(
                    $request->user()->id,
                    'review_content',
                    $data['review'],
                    $safetyCheck['violations'],
                    $safetyCheck['detected_patterns']
                );
                return $this->errorResponse($safetyCheck['friendly_warning'], 422);
            }
        }

        $review = DB::transaction(function () use ($project, $request, $data) {
            $review = $project->review()->create([...$data, 'client_id' => $request->user()->id, 'freelancer_profile_id' => $project->freelancer_profile_id]);
            $profile = $project->freelancerProfile()->lockForUpdate()->first();
            $count = (int) $profile->total_reviews + 1;
            $profile->update([
                'total_reviews' => $count,
                'rating' => round((((float) $profile->rating * ($count - 1)) + $data['rating']) / $count, 2),
                'total_projects' => DB::raw('total_projects + 1'),
            ]);
            return $review;
        });

        $this->createNotification($project->freelancerProfile->user_id, 'review', 'New review received', 'A client left a review for your completed project.', ['project_id' => $project->id, 'review_id' => $review->id]);
        return $this->successResponse($review, 'Review submitted successfully.', 201);
    }

    // FEATURE 13: Public Review Reply
    public function replyToReview(Request $request, FreelanceReview $review): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        $profile = $this->freelancerProfileFor($request);
        abort_unless($review->freelancer_profile_id === $profile->id, 403, 'You can only reply to reviews on your profile.');

        $data = $request->validate(['reply' => 'required|string|max:2000']);

        $safetyCheck = $this->safety->scanText($data['reply']);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation($request->user()->id, 'review_reply', $data['reply'], $safetyCheck['violations'], $safetyCheck['detected_patterns']);
            return $this->errorResponse($safetyCheck['friendly_warning'], 422);
        }

        $reply = $review->reply()->updateOrCreate(
            ['review_id' => $review->id],
            [
                'freelancer_profile_id' => $profile->id,
                'reply' => $data['reply'],
                'status' => 'approved',
            ]
        );

        $this->createNotification($review->client_id, 'review_reply', 'Freelancer replied to your review', 'The freelancer responded to your review.', ['review_id' => $review->id]);

        return $this->successResponse($reply, 'Reply to review posted successfully.');
    }

    // FEATURE 13: Deliverable Revision Discussion Comments
    public function deliverableComments(Request $request, FreelanceProject $project, FreelanceDeliverable $deliverable): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        abort_unless($deliverable->project_id === $project->id, 404);

        $comments = $deliverable->comments()->with('user:id,name,avatar')->orderBy('created_at')->get();
        return $this->successResponse($comments, 'Deliverable revision comments fetched successfully.');
    }

    public function addDeliverableComment(Request $request, FreelanceProject $project, FreelanceDeliverable $deliverable): JsonResponse
    {
        $this->ensureProjectParticipant($request, $project);
        abort_unless($deliverable->project_id === $project->id, 404);

        $data = $request->validate([
            'comment' => 'required|string|max:5000',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,pdf,doc,docx,zip|max:51200',
        ]);

        $safetyCheck = $this->safety->scanText($data['comment']);
        if ($safetyCheck['has_violations']) {
            $this->safety->recordViolation($request->user()->id, 'deliverable_comment', $data['comment'], $safetyCheck['violations'], $safetyCheck['detected_patterns']);
            return $this->errorResponse($safetyCheck['friendly_warning'], 422);
        }

        $attachmentPath = null;
        if ($request->hasFile('file')) {
            $attachmentPath = $this->storage->store($request->file('file'), "freelancing/deliverables/{$deliverable->id}/comments");
        }

        $comment = $deliverable->comments()->create([
            'user_id' => $request->user()->id,
            'comment' => $data['comment'],
            'attachments' => $attachmentPath ? [$attachmentPath] : null,
        ]);

        return $this->successResponse($comment->load('user:id,name,avatar'), 'Deliverable comment posted successfully.', 201);
    }

    public function wallet(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        return $this->successResponse(FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $profile->id])->load('transactions'), 'Wallet fetched successfully.');
    }

    public function updatePayoutSettings(Request $request): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        $validated = $request->validate([
            'upi_id' => 'nullable|string|max:100',
            'upi_phone' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:120',
            'account_holder_name' => 'nullable|string|max:120',
            'account_number' => 'nullable|string|max:60',
            'ifsc_code' => 'nullable|string|max:30',
            'account_type' => 'nullable|string|max:30',
            'preferred_method' => 'nullable|in:upi,bank',
        ]);

        $profile = $this->freelancerProfileFor($request);
        $wallet = FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $profile->id]);
        $existing = $wallet->payout_settings ?? [];
        $merged = array_merge($existing, array_filter($validated, fn($v) => $v !== null));
        $wallet->payout_settings = $merged;
        $wallet->save();

        return $this->successResponse($wallet->fresh('transactions'), 'Payout details updated successfully.');
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        return $this->paginatedResponse($profile->withdrawals()->latest()->paginate(20), 'Withdrawal history fetched successfully.');
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|max:30',
            'account_reference' => 'required|string|max:255',
        ]);

        $profile = $this->freelancerProfileFor($request);
        $withdrawal = DB::transaction(function () use ($profile, $data) {
            $wallet = FreelancerWallet::where('freelancer_profile_id', $profile->id)->lockForUpdate()->firstOrCreate(['freelancer_profile_id' => $profile->id]);
            abort_if(Money::toPaise($wallet->available_balance) < Money::toPaise($data['amount']), 422, 'Insufficient available balance.');
            $wallet->available_balance = Money::toRupees(Money::toPaise($wallet->available_balance) - Money::toPaise($data['amount']));
            $wallet->save();

            $wallet->transactions()->create([
                'type' => 'withdrawal_requested',
                'amount' => $data['amount'],
                'balance_after' => $wallet->available_balance,
                'reference' => 'WD-' . strtoupper(Str::random(10)),
                'description' => strtoupper($data['payment_method']) . ' payout to ' . $data['account_reference'],
            ]);

            return $profile->withdrawals()->create([...$data, 'requested_at' => now(), 'status' => 'pending']);
        });

        return $this->successResponse($withdrawal, 'Withdrawal request submitted successfully.', 201);
    }

    public function adminWithdrawals(Request $request): JsonResponse
    {
        $query = FreelancerWithdrawal::with('freelancerProfile.user:id,name,email')->latest();
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        return $this->paginatedResponse($query->paginate(30), 'Withdrawal requests fetched successfully.');
    }

    public function processWithdrawal(Request $request, FreelancerWithdrawal $withdrawal): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:approved,processing,completed,rejected,cancelled', 'admin_note' => 'nullable|string|max:2000']);
        abort_unless($withdrawal->status === 'pending' || $withdrawal->status === 'approved' || $withdrawal->status === 'processing', 422, 'This withdrawal is already finalized.');

        DB::transaction(function () use ($withdrawal, $data) {
            if (in_array($data['status'], ['rejected', 'cancelled'], true) && $withdrawal->status !== 'rejected' && $withdrawal->status !== 'cancelled') {
                $wallet = FreelancerWallet::where('freelancer_profile_id', $withdrawal->freelancer_profile_id)->lockForUpdate()->firstOrFail();
                $wallet->increment('available_balance', $withdrawal->amount);
                $wallet->refresh();
                $wallet->transactions()->create([
                    'type' => 'withdrawal_reversal',
                    'amount' => $withdrawal->amount,
                    'balance_after' => $wallet->available_balance,
                    'reference' => 'WAL-'.strtoupper(Str::random(12)),
                    'description' => 'Withdrawal request rejected or cancelled',
                ]);
            }
            $withdrawal->update([...$data, 'processed_at' => in_array($data['status'], ['completed', 'rejected', 'cancelled']) ? now() : null]);
        });

        return $this->successResponse($withdrawal->fresh('freelancerProfile.user'), 'Withdrawal status updated successfully.');
    }

    public function paymentWebhook(Request $request): JsonResponse
    {
        $signature = (string) $request->header('X-Razorpay-Signature');
        $secret = (string) config('services.razorpay.webhook_secret');
        abort_unless($secret !== '' && $signature !== '', 401, 'Invalid webhook signature.');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401, 'Invalid webhook signature.');

        $payload = $request->json()->all();
        $entity = data_get($payload, 'payload.payment.entity');
        if (! is_array($entity) || empty($entity['order_id'])) return $this->successResponse(null, 'Webhook ignored.');

        $payment = FreelancePayment::where('gateway_order_id', $entity['order_id'])->first();
        if (! $payment || in_array($payment->status, ['held', 'released', 'refunded'], true)) return $this->successResponse(null, 'Webhook already processed.');
        if ((int) ($entity['amount'] ?? 0) !== Money::toPaise($payment->amount)) return $this->errorResponse('Webhook amount mismatch.', 422);

        DB::transaction(function () use ($payment, $entity) {
            $payment->update(['gateway_payment_id' => $entity['id'] ?? null, 'status' => 'held', 'held_at' => now(), 'metadata' => $entity]);
            $project = $payment->project()->lockForUpdate()->firstOrFail();
            if ($project->status !== 'in_progress') $this->transitionProject($project, 'in_progress');

            // Record held funds and milestones
            $this->milestonePaymentService->fundProjectPayment($project, $payment->amount);

            FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $payment->project->freelancer_profile_id]);
        });

        return $this->successResponse(null, 'Webhook processed successfully.');
    }

    private function freelancerProfileFor(Request $request): FreelancerProfile
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        return FreelancerProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['professional_title' => 'Creative Professional', 'hourly_rate' => 500]
        );
    }

    private function ensureParticipant(Request $request, FreelanceConversation $conversation): void
    {
        abort_unless($conversation->participants()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function ensureRole(Request $request, string $role): void
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        if ($role === 'freelancer') {
            if ($user->role === 'freelancer' || $user->freelancerProfile()->exists()) {
                return;
            }
            FreelancerProfile::firstOrCreate(
                ['user_id' => $user->id],
                ['professional_title' => 'Creative Professional', 'hourly_rate' => 500]
            );
            return;
        }
        abort_unless($user->role === $role, 403, "A {$role} account is required for this action.");
    }

    private function ensureProjectParticipant(Request $request, FreelanceProject $project): void
    {
        abort_unless($project->client_id === $request->user()->id || $project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
    }

    private function transitionProject(FreelanceProject $project, string $status): void
    {
        abort_unless($project->canTransitionTo($status), 422, "Project cannot transition from {$project->status} to {$status}.");
        $project->update(['status' => $status]);
    }

    private function createNotification(int $userId, string $type, string $title, ?string $body = null, array $data = []): void
    {
        FreelanceNotification::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data]);
    }
}
