<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Campaign\ClientService;
use App\Services\Campaign\ClientAnalyticsService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(
        protected ClientService $clientService,
        protected ClientAnalyticsService $clientAnalyticsService,
    ) {}

    /**
     * GET /clients
     * List all clients with filtering, sorting, and pagination.
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'search', 'status',
            'spending_min', 'spending_max', 'referral_partner_id',
            'date_from', 'date_to', 'sort_by', 'sort_order',
        ]);
        $filters['per_page'] = $request->input('per_page', 25);

        $clients = $this->clientService->list($filters);

        return $this->paginatedResponse($clients, 'Clients fetched successfully');
    }

    /**
     * GET /clients/kpis
     * Get client module KPIs.
     */
    public function kpis()
    {
        $kpis = $this->clientAnalyticsService->kpis();
        return $this->successResponse($kpis, 'Client KPIs fetched successfully');
    }

    /**
     * GET /clients/{id}
     * Get detailed client profile.
     */
    public function show($id)
    {
        $profile = $this->clientService->getProfile((int) $id);
        return $this->successResponse($profile, 'Client profile fetched successfully');
    }

    /**
     * GET /clients/{id}/bookings
     * Get booking history for a client.
     */
    public function bookings(Request $request, $id)
    {
        $filters = $request->only(['status', 'date_from', 'date_to']);
        $filters['per_page'] = $request->input('per_page', 25);

        $bookings = $this->clientService->getBookings((int) $id, $filters);

        return $this->paginatedResponse($bookings, 'Client bookings fetched successfully');
    }

    /**
     * GET /clients/{id}/payments
     * Get payment history for a client.
     */
    public function payments(Request $request, $id)
    {
        $filters = $request->only(['payment_status', 'date_from', 'date_to']);
        $filters['per_page'] = $request->input('per_page', 25);

        $payments = $this->clientService->getPayments((int) $id, $filters);

        return $this->paginatedResponse($payments, 'Client payments fetched successfully');
    }

    /**
     * GET /clients/{id}/analytics
     * Get campaign analytics for a client.
     */
    public function analytics(Request $request, $id)
    {
        $filters = $request->only(['date_from', 'date_to']);

        $analytics = $this->clientAnalyticsService->clientCampaignAnalytics((int) $id, $filters);

        return $this->successResponse($analytics, 'Client analytics fetched successfully');
    }

    /**
     * GET /clients/{id}/referral
     * Get referral information for a client.
     */
    public function referral($id)
    {
        $referralInfo = $this->clientAnalyticsService->clientReferralInfo((int) $id);
        return $this->successResponse($referralInfo, 'Client referral info fetched successfully');
    }

    /**
     * GET /clients/{id}/timeline
     * Get activity timeline for a client.
     */
    public function timeline($id)
    {
        $timeline = $this->clientService->getTimeline((int) $id);
        return $this->successResponse($timeline, 'Client timeline fetched successfully');
    }
}
