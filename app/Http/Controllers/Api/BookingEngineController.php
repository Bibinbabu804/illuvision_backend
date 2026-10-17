<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingScreen;
use App\Models\Screen;
use App\Services\BookingWorkflowService;
use App\Services\ConflictDetectionService;
use App\Services\InventoryService;
use App\Services\PricingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingEngineController extends Controller
{
    public function __construct(
        protected BookingWorkflowService $workflowService,
        protected ConflictDetectionService $conflictService,
        protected PricingEngine $pricingEngine,
        protected InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Booking::with(['bookingScreens.screen', 'user']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $bookings = $query->orderBy('id', 'desc')->paginate($request->get('per_page', 15));

        return response()->json($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'campaign_title' => 'required|string|max:255',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'items' => 'required|array|min:1',
            'items.*.asset_id' => 'required|exists:screens,id',
            'items.*.quantity' => 'nullable|integer|min:1',
        ]);

        $items = $request->items;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        // 1. Validate capacity conflicts
        $this->conflictService->validateBookingConflicts($items, $startDate, $endDate);

        // 2. Compute pricing
        $calcResult = $this->pricingEngine->calculatePreview([
            'items' => $items,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        // 3. Create booking transaction
        $booking = DB::transaction(function () use ($request, $startDate, $endDate, $calcResult, $items) {
            $screenIds = array_column($items, 'asset_id');
            $this->inventoryService->lockScreensForBooking($screenIds);

            // Re-validate inside the exclusive lock
            $this->conflictService->validateBookingConflicts($items, $startDate, $endDate);

            $bookingRef = 'ILL-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 4));

            $booking = Booking::create([
                'user_id' => $request->user()?->id,
                'booking_reference' => $bookingRef,
                'title' => $request->campaign_title,
                'campaign_title' => $request->campaign_title,
                'advertiser_name' => $request->user()?->name ?? 'Guest Advertiser',
                'advertiser_email' => $request->user()?->email ?? 'guest@illuvision.net',
                'advertiser_phone' => $request->user()?->phone ?? '9000000000',
                'start_time' => $startDate . ' 00:00:00',
                'end_time' => $endDate . ' 23:59:59',
                'price' => $calcResult['pricing']['final_total'],
                'final_amount' => $calcResult['pricing']['final_total'],
                'subtotal' => $calcResult['pricing']['gross_total'],
                'discount_amount' => $calcResult['pricing']['duration_discount'],
                'status' => 'pending_approval',
            ]);

            foreach ($calcResult['line_items'] as $lineItem) {
                $asset = Screen::find($lineItem['asset_id']);
                BookingScreen::create([
                    'booking_id' => $booking->id,
                    'screen_id' => $asset->id,
                    'city_id' => $asset->city_id ?? 1,
                    'quantity' => $lineItem['quantity'],
                    'unit_price' => $lineItem['unit_price_per_day'],
                    'line_subtotal' => $lineItem['line_subtotal'],
                    'subtotal' => $lineItem['line_subtotal'],
                    'status' => 'pending',
                ]);
            }

            return $booking;
        });

        return response()->json([
            'message' => 'Booking created successfully.',
            'data' => $booking->load(['bookingScreens.screen']),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $booking = Booking::with(['bookingScreens.screen', 'user'])->findOrFail($id);

        return response()->json([
            'data' => $booking,
        ]);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $booking = Booking::findOrFail($id);
        $updated = $this->workflowService->transition($booking, $request->status, $request->reason);

        return response()->json([
            'message' => "Booking status updated to {$updated->status->value}.",
            'data' => $updated,
        ]);
    }
}
