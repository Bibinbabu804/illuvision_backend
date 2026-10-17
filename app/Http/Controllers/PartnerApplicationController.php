<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerApplicationController extends Controller
{
    public function __construct(
        private PartnerService $partnerService
    ) {}

    /**
     * List all partner applications with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $perPage = $request->input('perPage', 15);
        $status = $request->input('status');  // pending, under_review, approved, rejected
        $city = $request->input('city');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $applications = Partner::query()
            ->with(['reviewer'])
            ->withCount('referralCodes')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('contact_person', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('company', 'like', "%{$search}%");
                });
            })
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($city, function ($query) use ($city) {
                $query->where('city', $city);
            })
            ->when($dateFrom, function ($query) use ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($dateTo, function ($query) use ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            })
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);

        return $this->paginatedResponse($applications, 'Applications fetched successfully');
    }

    /**
     * Get single application detail.
     */
    public function show(Partner $partner): JsonResponse
    {
        $partner->load(['reviewer', 'referralCodes']);
        $partner->loadCount('referralCodes');

        return $this->successResponse(
            $partner,
            'Application details fetched successfully'
        );
    }

    /**
     * Get distinct cities for filter dropdown.
     */
    public function cities(): JsonResponse
    {
        $cities = Partner::whereNotNull('city')
            ->select('city')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        return $this->successResponse($cities, 'Cities fetched successfully');
    }

    /**
     * Add internal notes to an application.
     */
    public function addNote(Request $request, Partner $partner): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $existingNotes = $partner->notes;
        $newNote = "[" . now()->format('Y-m-d H:i') . "] " . $validated['notes'];
        $partner->update([
            'notes' => $existingNotes
                ? $existingNotes . "\n\n" . $newNote
                : $newNote,
        ]);

        return $this->successResponse(
            $partner->fresh(),
            'Notes added successfully'
        );
    }

    /**
     * Get application stats for dashboard KPIs.
     */
    public function stats(): JsonResponse
    {
        return $this->successResponse([
            'total_applications' => Partner::count(),
            'pending' => Partner::where('status', Partner::STATUS_PENDING)->count(),
            'under_review' => Partner::where('status', Partner::STATUS_UNDER_REVIEW)->count(),
            'approved' => Partner::where('status', Partner::STATUS_APPROVED)->count(),
            'rejected' => Partner::where('status', Partner::STATUS_REJECTED)->count(),
        ], 'Application stats fetched successfully');
    }
}
