<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\User;
use App\Services\ManualBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PartnerClientController extends Controller
{
    public function __construct(
        protected ManualBookingService $delegatedBookingEngine
    ) {}

    /**
     * List all clients belonging to the authenticated partner.
     * GET /partner/clients
     */
    public function index(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $perPage = $request->query('per_page', 15);
        $search = $request->query('search');

        $query = User::where('created_by_partner_id', $partner->id)
            ->withCount(['bookings as total_bookings' => fn($q) => $q->where('created_by_partner_id', $partner->id)])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%")
                       ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $clients = $query->paginate($perPage);

        return $this->successResponse($clients, 'Partner clients retrieved successfully.');
    }

    /**
     * Search clients belonging to the authenticated partner.
     * GET /partner/clients/search?q=...
     */
    public function search(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $queryStr = $request->query('q', '');
        if (strlen($queryStr) < 2) {
            return $this->successResponse([], 'Search query too short.');
        }

        $clients = User::where('created_by_partner_id', $partner->id)
            ->where(function ($q) use ($queryStr) {
                $q->where('name', 'like', "%{$queryStr}%")
                  ->orWhere('email', 'like', "%{$queryStr}%")
                  ->orWhere('phone', 'like', "%{$queryStr}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'phone', 'status', 'created_at']);

        return $this->successResponse($clients, 'Partner clients searched successfully.');
    }

    /**
     * Create a new client registered under the authenticated partner.
     * POST /partner/clients
     */
    public function store(Request $request): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $client = $this->delegatedBookingEngine->findOrCreateClient($validated, $partner->id);

        return $this->successResponse($client, 'Client profile created under Partner.', 201);
    }

    /**
     * View detailed profile, campaign history, booking history, and payment history for a specific client.
     * GET /partner/clients/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        // Strict Client Isolation Check
        $client = User::where('id', $id)
            ->where('created_by_partner_id', $partner->id)
            ->first();

        if (!$client) {
            return $this->errorResponse('Client not found or does not belong to your partner account.', 404);
        }

        // Fetch Booking & Campaign History
        $bookings = Booking::where('user_id', $client->id)
            ->where('created_by_partner_id', $partner->id)
            ->with(['screens.screen', 'referralCode'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Fetch Payment History across client bookings
        $bookingIds = $bookings->pluck('id');
        $payments = \App\Models\Payment::whereIn('booking_id', $bookingIds)
            ->with(['booking:id,title,final_amount'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse([
            'client' => $client,
            'stats' => [
                'total_bookings' => $bookings->count(),
                'active_campaigns' => $bookings->whereIn('status', [Booking::STATUS_RUNNING, Booking::STATUS_SCHEDULED])->count(),
                'total_spent' => (float) $bookings->where('status', '!=', Booking::STATUS_CANCELLED)->sum('final_amount'),
            ],
            'bookings' => $bookings,
            'payments' => $payments,
        ], 'Client details and history retrieved successfully.');
    }

    /**
     * Update client profile details.
     * PUT /partner/clients/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $partner = $this->getAuthenticatedPartner($request);
        if (!$partner) {
            return $this->errorResponse('Partner account not found or not approved.', 403);
        }

        // Strict Client Isolation Check
        $client = User::where('id', $id)
            ->where('created_by_partner_id', $partner->id)
            ->first();

        if (!$client) {
            return $this->errorResponse('Client not found or does not belong to your partner account.', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($client->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($client->id)],
        ]);

        $client->update($validated);

        return $this->successResponse($client->fresh(), 'Client profile updated successfully.');
    }

    /**
     * Resolve authenticated Partner model.
     */
    private function getAuthenticatedPartner(Request $request): ?Partner
    {
        $user = $request->user();
        if (!$user || $user->role !== 'partner') {
            return null;
        }

        return Partner::where('user_id', $user->id)->where('status', Partner::STATUS_APPROVED)->first();
    }
}
