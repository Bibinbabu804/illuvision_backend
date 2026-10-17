<?php

namespace App\Http\Controllers;

use App\Models\Screen;
use App\Models\ScreenProvider;
use App\Models\ScreenProviderSettlement;
use App\Services\RevenueDistributionService;
use App\Services\ScreenDeletionService;
use App\Services\ScreenProviderService;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminScreenProviderController extends Controller
{
    public function __construct(
        private ScreenProviderService $screenProviderService,
        private RevenueDistributionService $revenueDistributionService,
        private SettlementService $settlementService,
        private ScreenDeletionService $screenDeletionService,
    ) {
    }

    /**
     * Paginated list of screen providers with search, sort, and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $status = $request->status;
        $businessType = $request->business_type;
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';

        $query = ScreenProvider::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('provider_name', 'like', "%{$search}%")
                  ->orWhere('business_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($businessType) {
            $query->where('business_type', $businessType);
        }

        // Ensure valid sort column
        $allowedSorts = ['provider_name', 'business_name', 'email', 'phone', 'business_type', 'status', 'created_at', 'approved_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        $providers = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        // Add source label to each provider
        $providers->getCollection()->transform(function ($provider) {
            $provider->source = $provider->created_from_enquiry ? 'Enquiry' : 'Manual';
            $provider->source_label = $provider->created_from_enquiry ? 'From Enquiry' : 'Manual Entry';
            $provider->business_type_label = ScreenProvider::businessTypes()[$provider->business_type] ?? $provider->business_type;
            $provider->status_label = ScreenProvider::statuses()[$provider->status] ?? $provider->status;
            return $provider;
        });

        return $this->paginatedResponse($providers, 'Screen providers fetched successfully');
    }

    /**
     * Store a newly created screen provider (manual creation).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_name' => 'required|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255|unique:screen_providers,email',
            'business_type' => 'required|string|in:' . implode(',', array_keys(ScreenProvider::businessTypes())),
            'status' => 'sometimes|string|in:' . implode(',', array_keys(ScreenProvider::statuses())),
            'notes' => 'nullable|string',
            'illuvision_percentage' => 'nullable|numeric|min:0|max:100',
            'screen_provider_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        // Validate revenue split percentages if provided
        if (isset($validated['illuvision_percentage']) || isset($validated['screen_provider_percentage'])) {
            $illuvisionPct = $validated['illuvision_percentage'] ?? 10.00;
            $providerPct = $validated['screen_provider_percentage'] ?? 90.00;

            if (round($illuvisionPct + $providerPct, 2) !== 100.00) {
                return $this->errorResponse('Illuvision share and Screen Provider share must total 100%.', 422);
            }
        }

        try {
            $screenProvider = $this->screenProviderService->create($validated, $request->user());
            return $this->successResponse($screenProvider, 'Screen provider created successfully', 201);
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to create screen provider: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified screen provider.
     */
    public function show(ScreenProvider $screenProvider): JsonResponse
    {
        $screenProvider->load(['approvedByUser', 'createdByUser', 'enquiry']);

        $screenProvider->source = $screenProvider->created_from_enquiry ? 'Enquiry' : 'Manual';
        $screenProvider->business_type_label = ScreenProvider::businessTypes()[$screenProvider->business_type] ?? $screenProvider->business_type;
        $screenProvider->status_label = ScreenProvider::statuses()[$screenProvider->status] ?? $screenProvider->status;

        return $this->successResponse($screenProvider, 'Screen provider fetched successfully');
    }

    /**
     * Update the specified screen provider.
     */
    public function update(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $validated = $request->validate([
            'provider_name' => 'sometimes|required|string|max:255',
            'business_name' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'email' => 'sometimes|required|email|max:255',
            'business_type' => 'sometimes|required|string|in:' . implode(',', array_keys(ScreenProvider::businessTypes())),
            'status' => 'sometimes|required|string|in:' . implode(',', array_keys(ScreenProvider::statuses())),
            'notes' => 'nullable|string',
            'illuvision_percentage' => 'nullable|numeric|min:0|max:100',
            'screen_provider_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        // Validate revenue split percentages
        if (isset($validated['illuvision_percentage']) || isset($validated['screen_provider_percentage'])) {
            $illuvisionPct = $validated['illuvision_percentage'] ?? $screenProvider->illuvision_percentage;
            $providerPct = $validated['screen_provider_percentage'] ?? $screenProvider->screen_provider_percentage;

            if (round($illuvisionPct + $providerPct, 2) !== 100.00) {
                return $this->errorResponse('Illuvision share and Screen Provider share must total 100%.', 422);
            }
        }

        try {
            $screenProvider = $this->screenProviderService->update($screenProvider, $validated, $request->user());
            return $this->successResponse($screenProvider, 'Screen provider updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update screen provider: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update revenue split percentages for a screen provider.
     */
    public function updateRevenueSplit(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $validated = $request->validate([
            'illuvision_percentage' => 'required|numeric|min:0|max:100',
            'screen_provider_percentage' => 'required|numeric|min:0|max:100',
        ]);

        if (round($validated['illuvision_percentage'] + $validated['screen_provider_percentage'], 2) !== 100.00) {
            return $this->errorResponse('Illuvision share and Screen Provider share must total 100%.', 422);
        }

        try {
            $screenProvider = $this->screenProviderService->updateRevenueSplit(
                $screenProvider,
                $validated['illuvision_percentage'],
                $validated['screen_provider_percentage'],
                $request->user()
            );
            return $this->successResponse($screenProvider, 'Revenue split updated successfully');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to update revenue split: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get owned screens for a screen provider with search, pagination, sorting.
     */
    public function screens(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';

        $allowedSorts = ['name', 'screen_code', 'screen_type', 'city_id', 'location_name', 'status', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }

        $query = $screenProvider->screens()
            ->with(['country', 'city', 'pricingPlan']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('screen_code', 'like', "%{$search}%")
                  ->orWhere('location_name', 'like', "%{$search}%");
            });
        }

        $screens = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return $this->paginatedResponse($screens, 'Owned screens fetched successfully');
    }

    /**
     * Get activity timeline for a screen provider.
     */
    public function activity(ScreenProvider $screenProvider): JsonResponse
    {
        $activities = $this->screenProviderService->getActivityTimeline($screenProvider);
        return $this->successResponse($activities, 'Activity timeline fetched successfully');
    }

    /**
     * Add a note to a screen provider (logged as activity).
     */
    public function addNote(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $validated = $request->validate([
            'note' => 'required|string|max:5000',
        ]);

        $activity = $this->screenProviderService->addNote($screenProvider, $validated['note'], $request->user());

        return $this->successResponse($activity, 'Note added successfully');
    }

    /**
     * Get settlements for a specific screen provider.
     */
    public function settlements(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'status' => 'nullable|string|in:' . implode(',', array_keys(ScreenProviderSettlement::statuses())),
        ]);

        $settlements = $this->settlementService->getProviderSettlements(
            $screenProvider->id,
            $validated
        );

        $settlements->getCollection()->transform(function ($settlement) {
            $settlement->status_label = ScreenProviderSettlement::statuses()[$settlement->status] ?? $settlement->status;
            return $settlement;
        });

        return $this->paginatedResponse($settlements, 'Provider settlements fetched successfully');
    }

    /**
     * Get all screen providers as a simple list (for dropdowns).
     */
    public function allProviders(): JsonResponse
    {
        $providers = ScreenProvider::active()
            ->orderBy('provider_name')
            ->get(['id', 'provider_name', 'business_name', 'email']);

        $providers->transform(function ($provider) {
            $provider->display_name = $provider->business_name ?: $provider->provider_name;
            return $provider;
        });

        return $this->successResponse($providers, 'Screen providers fetched successfully');
    }

    /**
     * Get revenue statistics for a screen provider including settlement data.
     * Aggregates data from booking_screen snapshots (not live calculations).
     */
    public function revenue(ScreenProvider $screenProvider): JsonResponse
    {
        $stats = $this->settlementService->getProviderRevenueStatsWithSettlements($screenProvider->id);
        return $this->successResponse($stats, 'Revenue stats fetched successfully');
    }

    /**
     * Get per-screen revenue breakdown for a screen provider's owned screens.
     */
    public function screenRevenue(ScreenProvider $screenProvider): JsonResponse
    {
        $screenRevenue = $this->revenueDistributionService->getProviderScreenRevenue($screenProvider->id);
        return $this->successResponse($screenRevenue, 'Screen revenue data fetched successfully');
    }

    /**
     * Get booking history with revenue data for a screen provider.
     */
    public function bookingHistory(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $history = $this->revenueDistributionService->getProviderBookingHistory(
            $screenProvider->id,
            $validated
        );

        return $this->paginatedResponse($history, 'Booking history fetched successfully');
    }

    /**
     * Send portal invitation to a manually created or already approved screen provider.
     */
    public function sendPortalInvitation(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $admin = $request->user();
        $linkExisting = filter_var($request->input('link_existing'), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->screenProviderService->sendPortalInvitation($screenProvider, $admin, $linkExisting);
            return $this->successResponse($result, 'Portal invitation sent successfully');
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
            return $this->errorResponse('Failed to send portal invitation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete all active screens owned by a screen provider safely using ScreenDeletionService.
     */
    public function deleteScreens(Request $request, ScreenProvider $screenProvider): JsonResponse
    {
        $forceSafeOnly = filter_var($request->input('confirm_safe_only'), FILTER_VALIDATE_BOOLEAN);
        $result = $this->screenDeletionService->deleteProviderScreens($screenProvider->id, $forceSafeOnly);

        if (!$result['success']) {
            return $this->errorResponse($result['message'], 409, $result);
        }

        return $this->successResponse($result, $result['message']);
    }
}
