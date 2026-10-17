<?php

namespace App\Services\Campaign;

use App\Models\User;
use App\Models\Booking;
use App\Models\ReferralUsage;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClientService
{
    /**
     * List clients with filtering, sorting, and pagination.
     */
    public function list(array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 25;
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $spendingMin = $filters['spending_min'] ?? null;
        $spendingMax = $filters['spending_max'] ?? null;
        $referralPartnerId = $filters['referral_partner_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $query = User::query()
            ->select([
                'users.*',
                DB::raw('(SELECT COUNT(*) FROM bookings WHERE bookings.user_id = users.id AND bookings.status != "payment_pending") as total_campaigns'),
                DB::raw('(SELECT COALESCE(SUM(final_amount), 0) FROM bookings WHERE bookings.user_id = users.id AND bookings.status NOT IN ("cancelled", "payment_pending")) as total_spend'),
                DB::raw('(SELECT MAX(created_at) FROM bookings WHERE bookings.user_id = users.id AND bookings.status != "payment_pending") as last_booking_date'),
                DB::raw('(SELECT EXISTS(SELECT 1 FROM bookings WHERE bookings.user_id = users.id AND bookings.status IN ("approved","scheduled","running") LIMIT 1)) as has_active_bookings'),
            ]);

        // Text search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%")
                  ->orWhere('users.phone', 'like', "%{$search}%");
            });
        }

        // Status filter via subquery
        if ($status === 'active') {
            $query->where(DB::raw('(SELECT EXISTS(SELECT 1 FROM bookings WHERE bookings.user_id = users.id AND bookings.status IN ("approved","scheduled","running") LIMIT 1))'), 1);
        } elseif ($status === 'inactive') {
            $query->where(DB::raw('(SELECT EXISTS(SELECT 1 FROM bookings WHERE bookings.user_id = users.id AND bookings.status IN ("approved","scheduled","running","pending_approval","media_review_pending") LIMIT 1))'), 0);
        }

        // Referral partner filter
        if ($referralPartnerId) {
            $query->whereHas('bookings.referralCode', function ($q) use ($referralPartnerId) {
                $q->where('partner_id', $referralPartnerId);
            });
        }

        // Spending range filter via subquery
        if ($spendingMin !== null) {
            $query->where(DB::raw('(SELECT COALESCE(SUM(final_amount), 0) FROM bookings WHERE bookings.user_id = users.id AND bookings.status NOT IN ("cancelled", "payment_pending"))'), '>=', (float) $spendingMin);
        }
        if ($spendingMax !== null) {
            $query->where(DB::raw('(SELECT COALESCE(SUM(final_amount), 0) FROM bookings WHERE bookings.user_id = users.id AND bookings.status NOT IN ("cancelled", "payment_pending"))'), '<=', (float) $spendingMax);
        }

        // Date range filter
        if ($dateFrom) {
            $query->whereDate('users.created_at', '>=', Carbon::parse($dateFrom));
        }
        if ($dateTo) {
            $query->whereDate('users.created_at', '<=', Carbon::parse($dateTo));
        }

        // Sorting
        $allowedSorts = ['created_at', 'name', 'email', 'total_campaigns', 'total_spend', 'last_booking_date'];
        $sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'created_at';
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc']) ? $sortOrder : 'desc';

        if (in_array($sortBy, ['total_campaigns', 'total_spend', 'last_booking_date'])) {
            $query->orderBy(DB::raw($sortBy), $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        $clients = $query->paginate($perPage);

        // Batch-fetch referral data for all clients on this page to avoid N+1
        $clientIds = $clients->getCollection()->pluck('id')->filter()->toArray();
        $referralMap = $this->batchReferralData($clientIds);

        $clients->getCollection()->transform(function ($client) use ($referralMap) {
            $client->client_status = (bool) $client->has_active_bookings ? 'active' : 'inactive';
            $client->total_spend = (float) ($client->total_spend ?? 0);
            $client->total_campaigns = (int) ($client->total_campaigns ?? 0);

            $referralData = $referralMap[$client->id] ?? null;
            $client->referred_by = $referralData['partner_name'] ?? null;
            $client->referral_code = $referralData['code'] ?? null;

            // Clean up transient subquery attribute
            unset($client->has_active_bookings);

            return $client;
        });

        return $clients;
    }

    /**
     * Batch-fetch referral data for a set of user IDs.
     */
    protected function batchReferralData(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $usages = ReferralUsage::whereIn('user_id', $userIds)
            ->with('referralCode.partner')
            ->select('user_id', 'referral_code_id')
            ->selectRaw('MAX(created_at) as latest')
            ->groupBy('user_id', 'referral_code_id')
            ->orderBy('latest', 'desc')
            ->get();

        $map = [];
        foreach ($usages as $usage) {
            $partnerName = $usage->referralCode?->partner?->name;
            $code = $usage->referralCode?->code;
            // Only store the first (most recent) entry per user
            if (!isset($map[$usage->user_id])) {
                $map[$usage->user_id] = [
                    'partner_name' => $partnerName,
                    'code' => $code,
                ];
            }
        }

        return $map;
    }

    /**
     * Get detailed client profile.
     */
    public function getProfile(int $userId): array
    {
        $user = User::withCount([
            'bookings as total_campaigns',
            'bookings as active_campaigns' => function ($q) {
                $q->whereIn('status', [
                    Booking::STATUS_APPROVED,
                    Booking::STATUS_SCHEDULED,
                    Booking::STATUS_RUNNING,
                ]);
            },
        ])->findOrFail($userId);

        $totalSpend = (float) Booking::where('user_id', $userId)
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->sum('final_amount');

        $lastActivity = Booking::where('user_id', $userId)
            ->latest('created_at')
            ->value('created_at');

        // Referral info
        $referralUsage = ReferralUsage::where('user_id', $userId)
            ->with('referralCode.partner')
            ->latest()
            ->first();

        $referralSavings = (float) ReferralUsage::where('user_id', $userId)->sum('discount_amount');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'created_at' => $user->created_at,
            'total_campaigns' => (int) $user->total_campaigns,
            'active_campaigns' => (int) $user->active_campaigns,
            'total_spend' => $totalSpend,
            'referral_partner' => $referralUsage?->referralCode?->partner ? [
                'id' => $referralUsage->referralCode->partner->id,
                'name' => $referralUsage->referralCode->partner->name,
            ] : null,
            'referral_savings' => $referralSavings,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * Get booking history for a specific client.
     */
    public function getBookings(int $userId, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 25;
        $status = $filters['status'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $query = Booking::with([
            'bookingScreens.screen.city',
            'bookingMedia',
            'payments' => function ($q) {
                $q->latest()->limit(1);
            },
            'pricingPlan:id,name',
            'referralCode.partner',
        ])
            ->where('user_id', $userId);

        if ($status) {
            $statuses = explode(',', $status);
            $query->whereIn('status', $statuses);
        }

        if ($dateFrom) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom));
        }
        if ($dateTo) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        $query->orderBy('created_at', 'desc');

        $bookings = $query->paginate($perPage);

        // Enrich with computed fields
        $bookings->getCollection()->transform(function ($booking) {
            $latestPayment = $booking->payments->first();
            $booking->payment_status = $latestPayment?->payment_status ?? 'unpaid';
            $booking->paid_amount = (float) $booking->payments->where('payment_status', 'paid')->sum('amount');
            $booking->screens_count = $booking->bookingScreens->count() ?: ($booking->total_screens ?: 1);
            $booking->city_names = $booking->bookingScreens
                ->pluck('screen.city.name')
                ->filter()
                ->unique()
                ->values();
            return $booking;
        });

        return $bookings;
    }

    /**
     * Get payment history for a specific client.
     */
    public function getPayments(int $userId, array $filters = [])
    {
        $perPage = $filters['per_page'] ?? 25;
        $paymentStatus = $filters['payment_status'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $query = Payment::whereHas('booking', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        })
            ->with(['booking' => function ($q) {
                $q->select('id', 'campaign_title', 'title', 'final_amount', 'status');
            }]);

        if ($paymentStatus) {
            $query->where('payment_status', $paymentStatus);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', Carbon::parse($dateFrom));
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', Carbon::parse($dateTo));
        }

        $query->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Get activity timeline for a specific client.
     */
    public function getTimeline(int $userId): array
    {
        // Collect booking activities for all client bookings
        $activities = DB::table('booking_activities')
            ->join('bookings', 'booking_activities.booking_id', '=', 'bookings.id')
            ->where('bookings.user_id', $userId)
            ->where('bookings.status', '!=', Booking::STATUS_PAYMENT_PENDING)
            ->select(
                'booking_activities.id',
                'booking_activities.booking_id',
                'booking_activities.type',
                'booking_activities.label',
                'booking_activities.description',
                'booking_activities.created_at',
                'booking_activities.performed_by'
            )
            ->orderByDesc('booking_activities.created_at')
            ->limit(50)
            ->get()
            ->toArray();

        // Add payment events
        $payments = DB::table('payments')
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->where('bookings.user_id', $userId)
            ->whereNotNull('payments.paid_at')
            ->select(
                'payments.id',
                'payments.booking_id',
                DB::raw("'payment_completed' as type"),
                DB::raw("'Payment Completed' as label"),
                DB::raw("CONCAT('Payment of ', payments.amount, ' completed via ', COALESCE(payments.payment_method, 'razorpay')) as description"),
                'payments.paid_at as created_at',
                DB::raw('NULL as performed_by')
            )
            ->get()
            ->toArray();

        // Merge and sort
        $allEvents = array_merge($activities, $payments);
        usort($allEvents, function ($a, $b) {
            return strtotime($b->created_at) <=> strtotime($a->created_at);
        });

        return array_slice($allEvents, 0, 50);
    }
}
