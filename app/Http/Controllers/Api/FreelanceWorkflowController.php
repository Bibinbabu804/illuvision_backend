<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\FreelanceConversation;
use App\Models\FreelanceConversationParticipant;
use App\Models\FreelanceDeliverable;
use App\Models\FreelanceMessage;
use App\Models\FreelanceMessageAttachment;
use App\Models\FreelanceNotification;
use App\Models\FreelancePayment;
use App\Models\FreelanceProject;
use App\Models\FreelanceProjectFile;
use App\Models\FreelanceProposal;
use App\Models\FreelanceReview;
use App\Models\FreelancerProfile;
use App\Models\FreelancerService;
use App\Models\FreelancerServicePackage;
use App\Models\FreelancerWallet;
use App\Models\FreelancerWalletTransaction;
use App\Models\FreelancerWithdrawal;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Domain\Payment\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreelanceWorkflowController extends Controller
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected StorageContractInterface $storage,
    ) {}

    public function projects(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = FreelanceProject::with(['service', 'client:id,name,avatar', 'freelancerProfile.user:id,name,avatar'])->latest();
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
        $projects = FreelanceProject::with(['client:id,name,avatar', 'service:id,title', 'payments:id,project_id,status,amount', 'deliverables:id,project_id,title,submitted_at'])
            ->where('freelancer_profile_id', $profile->id)->latest()->get();
        $conversations = FreelanceConversation::with(['project:id,title,status', 'participants.user:id,name,avatar', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))->latest('last_message_at')->limit(5)->get();
        $proposals = FreelanceProposal::with('project.client:id,name,avatar')->where('freelancer_profile_id', $profile->id)->latest()->limit(10)->get();

        $reviews = FreelanceReview::with(['client:id,name,avatar', 'project:id,title,reference'])
            ->where('freelancer_profile_id', $profile->id)->latest()->limit(5)->get();

        return $this->successResponse([
            'profile' => new \App\Http\Resources\FreelancerProfileResource($profile->load('user:id,name,avatar', 'skills', 'services.packages', 'portfolioItems.media')),
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
        $query = FreelanceReview::with(['client:id,name,avatar', 'project:id,title,reference'])
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
        return $this->successResponse($project->load(['service', 'package', 'client:id,name,avatar', 'freelancerProfile.user:id,name,avatar', 'proposals', 'deliverables.submitter:id,name', 'files.uploader:id,name', 'payments', 'review']), 'Project fetched successfully.');
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
        ]);
        $service = FreelancerService::with('packages')->where('status', 'active')->findOrFail($data['service_id']);
        $package = $service->packages()->findOrFail($data['package_id']);

        $project = DB::transaction(function () use ($request, $service, $package, $data) {
            $grossPaise = Money::toPaise($package->price);
            $feePaise = (int) round($grossPaise * 0.10);
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
                'freelancer_amount' => Money::toRupees($grossPaise - $feePaise),
                'delivery_days' => $package->delivery_days,
                'revision_limit' => $package->revisions,
            ]);
            $conversation = FreelanceConversation::create(['project_id' => $project->id, 'freelancer_service_id' => $service->id]);
            $conversation->participants()->createMany([
                ['user_id' => $request->user()->id],
                ['user_id' => $service->freelancerProfile->user_id],
            ]);
            return $project;
        });
        $this->createNotification($service->freelancerProfile->user_id, 'project', 'New project request', "A client requested {$project->title}.", ['project_id' => $project->id]);

        return $this->successResponse($project->load('service', 'freelancerProfile.user', 'conversation'), 'Project request created successfully.', 201);
    }

    public function sendProposal(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        abort_unless($project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless(in_array($project->status, ['requested', 'freelancer_reviewing']), 422);
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'delivery_days' => 'required|integer|min:1|max:3650', 'revisions' => 'nullable|integer|min:0|max:100', 'description' => 'nullable|string|max:10000', 'expires_at' => 'nullable|date|after:now']);
        $proposal = DB::transaction(function () use ($project, $request, $data) {
            $proposal = $project->proposals()->updateOrCreate(['freelancer_profile_id' => $project->freelancer_profile_id], [...$data, 'status' => 'pending']);
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
            $project->update(['gross_amount' => Money::toRupees($amountPaise), 'platform_fee' => Money::toRupees($feePaise), 'freelancer_amount' => Money::toRupees($amountPaise - $feePaise), 'delivery_days' => $proposal->delivery_days, 'revision_limit' => $proposal->revisions]);
            $project->proposals()->where('id', '!=', $proposal->id)->update(['status' => 'rejected']);
        });
        return $this->successResponse($project->fresh(), 'Proposal accepted. Payment is now required.');
    }

    public function conversations(Request $request): JsonResponse
    {
        $items = FreelanceConversation::with('participants.user:id,name,avatar')->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))->latest('last_message_at')->paginate(20);
        return $this->paginatedResponse($items, 'Conversations fetched successfully.');
    }

    public function messages(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);
        return $this->paginatedResponse($conversation->messages()->with('sender:id,name,avatar', 'attachments')->latest()->paginate(30), 'Messages fetched successfully.');
    }

    public function markConversationRead(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);
        $conversation->participants()->where('user_id', $request->user()->id)->update(['last_read_at' => now()]);
        $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->update(['read_at' => now()]);
        return $this->successResponse(null, 'Conversation marked as read.');
    }

    public function sendMessage(Request $request, FreelanceConversation $conversation): JsonResponse
    {
        $this->ensureParticipant($request, $conversation);
        $data = $request->validate(['body' => 'nullable|string|max:20000', 'message_type' => 'nullable|in:text,image,video,audio,file,offer', 'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,webm,mp3,wav,m4a,pdf,doc,docx,zip|max:51200']);
        abort_if(empty($data['body']) && ! $request->hasFile('file'), 422, 'A message or attachment is required.');
        $message = DB::transaction(function () use ($request, $conversation, $data) {
            $message = $conversation->messages()->create(['sender_id' => $request->user()->id, 'message_type' => $data['message_type'] ?? 'text', 'body' => $data['body'] ?? null]);
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $path = $this->storage->store($file, "freelancing/conversations/{$conversation->id}");
                $message->attachments()->create(['uploaded_by' => $request->user()->id, 'original_name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
            }
            $conversation->update(['last_message_at' => now()]);
            return $message;
        });
        $conversation->participants()->where('user_id', '!=', $request->user()->id)->pluck('user_id')->each(function ($userId) use ($conversation) {
            $this->createNotification((int) $userId, 'message', 'New client message', 'You have a new message in your project conversation.', ['conversation_id' => $conversation->id, 'project_id' => $conversation->project_id]);
        });
        return $this->successResponse($message->load('sender:id,name,avatar', 'attachments'), 'Message sent successfully.', 201);
    }

    public function submitDeliverable(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        abort_unless($project->freelancerProfile()->where('user_id', $request->user()->id)->exists(), 403);
        abort_unless(in_array($project->status, ['in_progress', 'revision_requested']), 422);
        $data = $request->validate(['title' => 'required|string|max:255', 'message' => 'nullable|string|max:10000', 'is_final' => 'boolean']);
        $deliverable = DB::transaction(function () use ($project, $request, $data) {
            $deliverable = $project->deliverables()->create([...$data, 'submitted_by' => $request->user()->id, 'revision_number' => $project->revision_count + 1, 'submitted_at' => now()]);
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
        $project = DB::transaction(function () use ($project) {
            $project = FreelanceProject::whereKey($project->id)->lockForUpdate()->firstOrFail();
            abort_unless($project->status === 'submitted', 422);
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
                $wallet->transactions()->create(['project_id' => $project->id, 'type' => 'project_payment', 'amount' => $project->freelancer_amount, 'balance_after' => $wallet->available_balance, 'reference' => 'WAL-'.strtoupper(Str::random(12)), 'description' => 'Project payment released']);
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
        $order = $this->gateway->createOrder(['amount' => Money::toPaise($project->gross_amount), 'currency' => Money::GATEWAY_CURRENCY, 'receipt' => $project->reference]);
        $payment = $project->payments()->create(['client_id' => $request->user()->id, 'gateway_order_id' => $order['id'] ?? null, 'amount' => $project->gross_amount, 'status' => 'pending', 'metadata' => $order]);
        return $this->successResponse(['payment' => $payment, 'gateway_order' => $order], 'Payment order created successfully.', 201);
    }

    public function verifyPayment(Request $request, FreelancePayment $payment): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($payment->client_id === $request->user()->id, 403);
        if (in_array($payment->status, ['held', 'released'], true)) {
            return $this->successResponse($payment->load('project'), 'Payment was already verified.');
        }
        $data = $request->validate(['gateway_payment_id' => 'required|string', 'gateway_signature' => 'required|string']);
        abort_unless($this->gateway->verifySignature($payment->gateway_order_id, $data['gateway_payment_id'], $data['gateway_signature']), 422, 'Payment signature verification failed.');
        DB::transaction(function () use ($payment, $data) {
            $payment = FreelancePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, ['held', 'released'], true)) return;
            $payment->update([...$data, 'status' => 'held', 'held_at' => now()]);
            $project = $payment->project()->lockForUpdate()->firstOrFail();
            if ($project->status !== 'in_progress') $this->transitionProject($project, 'in_progress');
            FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $payment->project->freelancer_profile_id]);
        });
        $this->createNotification($payment->project->freelancerProfile->user_id, 'payment', 'Payment funded', "Payment for {$payment->project->title} is held and work can begin.", ['project_id' => $payment->project_id, 'payment_id' => $payment->id]);
        return $this->successResponse($payment->fresh('project'), 'Payment verified and funds held successfully.');
    }

    public function createReview(Request $request, FreelanceProject $project): JsonResponse
    {
        $this->ensureRole($request, 'client');
        abort_unless($project->client_id === $request->user()->id, 403);
        abort_unless($project->status === 'completed' && ! $project->review()->exists(), 422);
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'review' => 'nullable|string|max:5000']);
        $review = DB::transaction(function () use ($project, $request, $data) {
            $review = $project->review()->create([...$data, 'client_id' => $request->user()->id, 'freelancer_profile_id' => $project->freelancer_profile_id]);
            $profile = $project->freelancerProfile()->lockForUpdate()->first();
            $count = (int) $profile->total_reviews + 1;
            $profile->update(['total_reviews' => $count, 'rating' => round((((float) $profile->rating * ($count - 1)) + $data['rating']) / $count, 2), 'total_projects' => DB::raw('total_projects + 1')]);
            return $review;
        });
        $this->createNotification($project->freelancerProfile->user_id, 'review', 'New review received', 'A client left a review for your completed project.', ['project_id' => $project->id, 'review_id' => $review->id]);
        return $this->successResponse($review, 'Review submitted successfully.', 201);
    }

    public function wallet(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        return $this->successResponse(FreelancerWallet::firstOrCreate(['freelancer_profile_id' => $profile->id])->load('transactions'), 'Wallet fetched successfully.');
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $profile = $this->freelancerProfileFor($request);
        return $this->paginatedResponse($profile->withdrawals()->latest()->paginate(20), 'Withdrawal history fetched successfully.');
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $this->ensureRole($request, 'freelancer');
        $data = $request->validate(['amount' => 'required|numeric|min:1', 'payment_method' => 'required|string|max:30', 'account_reference' => 'required|string|max:255']);
        $profile = $this->freelancerProfileFor($request);
        $withdrawal = DB::transaction(function () use ($profile, $data) {
            $wallet = FreelancerWallet::where('freelancer_profile_id', $profile->id)->lockForUpdate()->firstOrCreate(['freelancer_profile_id' => $profile->id]);
            abort_if(Money::toPaise($wallet->available_balance) < Money::toPaise($data['amount']), 422, 'Insufficient available balance.');
            $wallet->available_balance = Money::toRupees(Money::toPaise($wallet->available_balance) - Money::toPaise($data['amount']));
            $wallet->save();
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
                    'type' => 'withdrawal_reversal', 'amount' => $withdrawal->amount,
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
        if ($role === 'client') {
            return; // Any user can purchase or request as a client
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
