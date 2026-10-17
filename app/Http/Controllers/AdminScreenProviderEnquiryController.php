<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\ScreenProviderLeadAssigned;
use App\Support\Events\Notifications\ScreenProviderLeadClosed;
use App\Support\Events\Notifications\ScreenProviderLeadConverted;
use App\Models\ScreenProviderEnquiry;
use App\Models\User;
use App\Services\ScreenProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminScreenProviderEnquiryController extends Controller
{
    public function __construct(
        private ScreenProviderService $screenProviderService
    ) {
    }

    public function index(Request $request)
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $status = $request->status;
        $assignedTo = $request->assigned_to;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';

        $query = ScreenProviderEnquiry::with('assignedUser');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // if ($status) {
        //     $query->where('status', $status);
        // }

        if ($assignedTo) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $enquiries = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return $this->paginatedResponse($enquiries, 'Screen provider enquiries fetched successfully');
    }

    public function show(ScreenProviderEnquiry $screenProviderEnquiry)
    {
        $screenProviderEnquiry->load('assignedUser');
        return $this->successResponse($screenProviderEnquiry, 'Screen provider enquiry fetched successfully');
    }

    public function update(Request $request, ScreenProviderEnquiry $screenProviderEnquiry)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:new,contacted,qualified,converted,closed,spam',
            'internal_notes' => 'nullable|string',
        ]);

        $oldStatus = $screenProviderEnquiry->status;
        $screenProviderEnquiry->update($validated);

        // Fire converted event if status changed to converted
        if ($validated['status'] === 'converted' && $oldStatus !== 'converted') {
            ScreenProviderLeadConverted::dispatch($screenProviderEnquiry);
        }

        // Fire closed event if status changed to closed
        if ($validated['status'] === 'closed' && $oldStatus !== 'closed') {
            ScreenProviderLeadClosed::dispatch($screenProviderEnquiry);
        }

        return $this->successResponse($screenProviderEnquiry, 'Screen provider enquiry updated successfully');
    }

    public function assign(Request $request, ScreenProviderEnquiry $screenProviderEnquiry)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $screenProviderEnquiry->update([
            'assigned_to' => $validated['assigned_to'],
        ]);

        $user = User::find($validated['assigned_to']);
        if ($user) {
            ScreenProviderLeadAssigned::dispatch($screenProviderEnquiry, $request->user());
        }

        $screenProviderEnquiry->load('assignedUser');

        return $this->successResponse($screenProviderEnquiry, 'Screen provider enquiry assigned successfully');
    }

    public function status(Request $request, ScreenProviderEnquiry $screenProviderEnquiry)
    {
        $validated = $request->validate([
            'status' => 'required|in:new,contacted,qualified,converted,closed,spam',
        ]);

        $oldStatus = $screenProviderEnquiry->status;
        $screenProviderEnquiry->update(['status' => $validated['status']]);

        if ($validated['status'] === 'converted' && $oldStatus !== 'converted') {
            ScreenProviderLeadConverted::dispatch($screenProviderEnquiry);
        }

        if ($validated['status'] === 'closed' && $oldStatus !== 'closed') {
            ScreenProviderLeadClosed::dispatch($screenProviderEnquiry);
        }

        return $this->successResponse($screenProviderEnquiry, 'Screen provider enquiry status updated successfully');
    }

    public function notes(Request $request, ScreenProviderEnquiry $screenProviderEnquiry)
    {
        $validated = $request->validate([
            'internal_notes' => 'required|string',
        ]);

        $screenProviderEnquiry->update([
            'internal_notes' => $validated['internal_notes'],
        ]);

        return $this->successResponse($screenProviderEnquiry, 'Internal notes updated successfully');
    }

    /**
     * Approve an enquiry - creates a Screen Provider record.
     */
    public function approve(Request $request, ScreenProviderEnquiry $screenProviderEnquiry): JsonResponse
    {
        if (!in_array($screenProviderEnquiry->status, ['new', 'contacted', 'qualified'])) {
            return $this->errorResponse('Only new, contacted, or qualified enquiries can be approved.', 400);
        }

        $admin = $request->user();
        $linkExisting = filter_var($request->input('link_existing'), FILTER_VALIDATE_BOOLEAN);

        try {
            $screenProvider = $this->screenProviderService->approveFromEnquiry($screenProviderEnquiry, $admin, $linkExisting);
            return $this->successResponse([
                'screen_provider' => $screenProvider,
                'enquiry' => $screenProviderEnquiry->fresh(),
            ], 'Enquiry approved and screen provider created successfully');
        } catch (\App\Support\Exceptions\UserConflictException $e) {
            return response()->json([
                'status' => false,
                'requires_link_confirmation' => true,
                'message' => $e->getMessage(),
                'user' => [
                    'id' => $e->getUser()->id,
                    'name' => $e->getUser()->name,
                    'email' => $e->getUser()->email,
                ],
            ], 409);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to approve enquiry: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject an enquiry with optional reason.
     */
    public function reject(Request $request, ScreenProviderEnquiry $screenProviderEnquiry): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($screenProviderEnquiry->status === 'converted') {
            return $this->errorResponse('Cannot reject a converted enquiry.', 400);
        }

        $admin = $request->user();

        try {
            $enquiry = $this->screenProviderService->rejectEnquiry($screenProviderEnquiry, $admin, $validated['reason'] ?? null);

            // Dispatch rejection event
            ScreenProviderLeadClosed::dispatch($enquiry);

            return $this->successResponse($enquiry, 'Enquiry rejected successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to reject enquiry: ' . $e->getMessage(), 500);
        }
    }
}
