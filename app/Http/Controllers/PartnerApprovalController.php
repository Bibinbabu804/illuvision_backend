<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Services\PartnerService;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerApprovalController extends Controller
{
    public function __construct(
        private PartnerService $partnerService
    ) {
    }

    /**
     * Approve a partner application.
     */
    public function approve(Partner $partner, Request $request): JsonResponse
    {
        if (!in_array($partner->status, [Partner::STATUS_PENDING, Partner::STATUS_UNDER_REVIEW])) {
            return $this->errorResponse('Only pending or under review partners can be approved.', 400);
        }

        if ($partner->user_id) {
            return $this->errorResponse('This partner already has a linked user account.', 400);
        }

        $admin = $request->user();

        try {
            $result = $this->partnerService->approve($partner, $admin);
            return $this->successResponse( $result,'Partner approved successfully.');
        } catch (\Exception $e) {
            return $this->errorResponse( 'Failed to approve partner: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject a partner application.
     */
    public function reject(Partner $partner, Request $request): JsonResponse
    {
        if ($partner->isApproved()) {
            return $this->errorResponse('Cannot reject an already approved partner.', 400);
        }

        $admin = $request->user();
        $reason = $request->input('reason');

        $partner = $this->partnerService->reject($partner, $admin, $reason);

        return $this->successResponse( [
            'partner' => $partner,
        ],'Partner rejected.');
    }

    /**
     * Mark partner as under review.
     */
    public function markUnderReview(Partner $partner): JsonResponse
    {
        if (!$partner->isPending()) {
            return $this->errorResponse('Only pending partners can be moved to under review.', 400);
        }

        $partner->update(['status' => Partner::STATUS_UNDER_REVIEW]);

        return $this->successResponse([
            'partner' => $partner->fresh(),
        ],'message');
    }

    /**
     * Submit partner application from the website.
     */
    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:partners,email',
            'phone' => ['required', 'string', 'max:20', Rule::unique('partners', 'phone')->whereNot('status', 'rejected')],
            'city' => 'required|string|max:255',
            'profession' => 'nullable|string|max:255',
            'company' => 'nullable|string|max:255',
            'message' => 'nullable|string',
        ]);

        $partner = Partner::create([
            'name' => $validated['name'],
            'contact_person' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'city' => $validated['city'] ?? null,
            'profession' => $validated['profession'] ?? null,
            'company' => $validated['company'] ?? null,
            'message' => $validated['message'] ?? null,
            'status' => Partner::STATUS_PENDING,
        ]);

        // Fire event for admin notification
        \App\Support\Events\Notifications\PartnerCreated::dispatch($partner);

        return $this->successResponse(['partner' => $partner], 'Application submitted successfully. We will review and get back to you.');
    }
}
