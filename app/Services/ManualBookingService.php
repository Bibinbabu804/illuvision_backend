<?php

namespace App\Services;

use App\Application\Financial\UseCases\DistributeBookingRevenueUseCase;
use App\Application\Financial\UseCases\RecordReferralAttributionUseCase;
use App\Support\Events\Notifications\PaymentReceived;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Models\BookingMedia;
use App\Models\BookingPricingBreakdown;
use App\Models\BookingScreen;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManualBookingService
{
    public function __construct(
        protected CampaignPricingService $campaignPricingService,
        protected BookingCapacityService $capacityService,
        protected InventoryService $inventoryService,
        protected MediaService $mediaService,
        protected PaymentService $paymentService,
        protected DistributeBookingRevenueUseCase $distributeBookingRevenue,
        protected RecordReferralAttributionUseCase $recordReferralAttribution,
        protected RevenueSplitResolverService $splitResolver,
    ) {}

    /**
     * Search existing clients by name, email, or phone.
     *
     * @param string $query
     * @return \Illuminate\Support\Collection
     */
    public function searchClients(string $query)
    {
        return User::where(function ($q) use ($query) {
            $q->where('name', 'like', "%{$query}%")
              ->orWhere('email', 'like', "%{$query}%")
              ->orWhere('phone', 'like', "%{$query}%");
        })
        ->orderBy('name')
        ->limit(20)
        ->get(['id', 'name', 'email', 'phone', 'created_at']);
    }

    /**
     * Find or create a client user profile.
     *
     * @param array $data  { name, email?, phone? }
     * @param int|null $partnerId  Optional partner ID to assign ownership
     * @return User
     */
    public function findOrCreateClient(array $data, ?int $partnerId = null): User
    {
        // Try to find by email first, then phone
        $client = null;
        if (!empty($data['email'])) {
            $client = User::where('email', $data['email'])->first();
        }
        if (!$client && !empty($data['phone'])) {
            $client = User::where('phone', $data['phone'])->first();
        }

        if ($client) {
            // Update existing client info if provided
            $updateData = [];
            if (!empty($data['name'])) $updateData['name'] = $data['name'];
            if (!empty($data['email'])) $updateData['email'] = $data['email'];
            if (!empty($data['phone'])) $updateData['phone'] = $data['phone'];
            if ($partnerId && !$client->created_by_partner_id) {
                $updateData['created_by_partner_id'] = $partnerId;
            }
            if (!empty($updateData)) {
                $client->update($updateData);
            }
            return $client;
        }

        // Create new client
        return User::create([
            'name' => $data['name'] ?? 'Unknown Client',
            'email' => $data['email'] ?? ('client_' . Str::random(8) . '@temp.illuvission.com'),
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make(Str::random(16)),
            'role' => 'user',
            'created_by_partner_id' => $partnerId,
        ]);
    }

    /**
     * Calculate campaign pricing for manual booking.
     * Delegates entirely to CampaignPricingService.
     *
     * @param array $data
     * @return array
     * @throws PricingException
     */
    public function calculatePricing(array $data): array
    {
        $user = $data['user'] ?? null;
        return $this->campaignPricingService->calculateCampaignPreview(array_merge($data, ['user' => $user]));
    }

    /**
     * Create a manual campaign booking from admin.
     *
     * @param array $data  {
     *   client: { name, email?, phone? },
     *   booking_source: string,
     *   campaign_title: string,
     *   ad_frequency: string,
     *   start_time: string,
     *   end_time: string,
     *   daily_start_time?: string (H:i),
     *   daily_end_time?: string (H:i),
     *   screen_ids: int[],
     *   total_screens: int,
     *   subtotal: float,
     *   discount_amount: float,
     *   referral_code_id?: int,
     *   referral_discount: float,
     *   final_amount: float,
     *   pricing_plan_id?: int,
     *   notes?: string,
     *   sales_notes?: string,
     *   internal_notes?: string,
     *   payment_mode?: string,
     *   offline_payment_reference?: string,
     *   bypass_approval?: bool,
     * }
     * @param User $admin  The authenticated admin creating the booking
     * @return Booking
     */
    public function createCampaignBooking(array $data, User $admin): Booking
    {
        return DB::transaction(function () use ($data, $admin) {
            // 0. Atomic inventory row locking
            $this->inventoryService->lockScreensForBooking($data['screen_ids']);

            // 1. Find or create the client
            $partnerId = $data['created_by_partner_id'] ?? null;
            $client = $this->findOrCreateClient($data['client'], $partnerId);

            // Check if any screens are Sold Out / unavailable
            $soldOutScreensCount = Screen::whereIn('id', $data['screen_ids'])
                ->where('booking_status', 'sold_out')
                ->count();

            if ($soldOutScreensCount > 0) {
                throw new \RuntimeException(
                    'One or more selected screens are no longer available for booking. Please review your selection.',
                    422
                );
            }

            // Derive normalized date and time boundaries
            $startCarbon = Carbon::parse($data['start_time']);
            $endCarbon = Carbon::parse($data['end_time']);
            $startDate = $startCarbon->format('Y-m-d');
            $endDate = $endCarbon->format('Y-m-d');

            $dailyStartTime = $data['daily_start_time'] ?? ($startCarbon->format('H:i') !== '00:00' ? $startCarbon->format('H:i') : '00:00');
            $dailyEndTime = $data['daily_end_time'] ?? ($endCarbon->format('H:i') !== '00:00' ? $endCarbon->format('H:i') : '23:59');

            if ($dailyStartTime === '00:00' && $dailyEndTime === '00:00' && $startDate === $endDate) {
                $dailyEndTime = '23:59';
            }

            // 2a. Validate minimum campaign duration for each screen's pricing plan
            $durationErrors = $this->campaignPricingService->validateMinimumDuration(
                $data['screen_ids'],
                $startDate,
                $endDate,
                $dailyStartTime,
                $dailyEndTime
            );

            if (!empty($durationErrors)) {
                throw new \RuntimeException(
                    'The selected campaign duration does not meet the minimum requirement for one or more selected screens: ' . implode(' ', $durationErrors),
                    422
                );
            }

            // 2b. Authoritative unified capacity & availability validation (Daily Quota + Fixed Screen Time Collision + Mobile Fleet Line Sweep)
            $items = [];
            foreach ($data['screen_ids'] as $screenId) {
                $items[] = [
                    'screen_id' => $screenId,
                    'quantity' => max(1, (int) ($data['quantities'][$screenId] ?? 1)),
                ];
            }

            $eligibility = $this->capacityService->checkEligibility(
                $items,
                $data['start_time'],
                $data['end_time']
            );

            if (!$eligibility->isEligible()) {
                throw new \RuntimeException($eligibility->getFirstErrorMessage(), 422);
            }

            // 3. Validate server-side pricing
            $pricingValidation = $this->campaignPricingService->validateCampaignPricing([
                'screen_ids' => $data['screen_ids'],
                'quantities' => $data['quantities'] ?? [],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'daily_start_time' => $dailyStartTime,
                'daily_end_time' => $dailyEndTime,
                'referral_code_id' => $data['referral_code_id'] ?? null,
                'user' => $client,
            ]);

            if (!$pricingValidation['valid']) {
                throw new \RuntimeException(
                    'Pricing validation failed: ' . implode(' ', $pricingValidation['errors']),
                    422
                );
            }

            // 4. Determine initial booking status
            $bypassApproval = $data['bypass_approval'] ?? false;
            $initialStatus = $bypassApproval
                ? Booking::STATUS_APPROVED
                : Booking::STATUS_PAYMENT_PENDING;

            // If offline payment recorded, skip payment pending
            $hasOfflinePayment = !empty($data['payment_mode'])
                && in_array($data['payment_mode'], ['bank_transfer', 'cash', 'upi', 'manual', 'direct_bank', 'direct_upi', 'direct_cash', 'cheque']);

            if ($hasOfflinePayment) {
                $initialStatus = $bypassApproval
                    ? Booking::STATUS_APPROVED
                    : Booking::STATUS_PENDING_APPROVAL;
            }

            $pricingSnapshot = null;
            $rewardPointsRedeemed = 0;
            $rewardDiscountAmount = 0.00;

            $authoritativeSubtotal = (float) ($data['subtotal'] ?? 0);
            $authoritativeDiscount = (float) ($data['discount_amount'] ?? 0);
            $authoritativeReferral = (float) ($data['referral_discount'] ?? 0);
            $authoritativeFinal = (float) ($data['final_amount'] ?? 0);

            if ($pricingValidation['valid'] && isset($pricingValidation['calculated']['aggregated'])) {
                $agg = $pricingValidation['calculated']['aggregated'];
                $rewardPointsRedeemed = $agg['reward_points_used'] ?? 0;
                $rewardDiscountAmount = $agg['reward_discount'] ?? 0.00;
                $authoritativeSubtotal = (float) ($agg['subtotal'] ?? $agg['base_price'] ?? $data['subtotal'] ?? 0);
                $authoritativeDiscount = (float) ($agg['discount_amount'] ?? $agg['combined_discount'] ?? 0);
                $authoritativeReferral = (float) ($agg['referral_discount'] ?? 0);
                $authoritativeFinal = (float) ($agg['final_amount'] ?? $agg['final_price'] ?? $data['final_amount'] ?? 0);

                $pricingSnapshot = [
                    'base_price' => $agg['base_price'],
                    'screen_count' => $agg['screen_count'],
                    'screen_discount' => $agg['screen_discount'],
                    'duration_discount' => $agg['duration_discount'],
                    'combined_discount' => $agg['combined_discount'],
                    'peak_adjustment' => $agg['peak_adjustment'],
                    'weekend_adjustment' => $agg['weekend_adjustment'],
                    'first_booking_discount' => $agg['first_booking_discount'],
                    'referral_discount' => $agg['referral_discount'],
                    'reward_points_used' => $rewardPointsRedeemed,
                    'reward_discount' => $rewardDiscountAmount,
                    'final_amount' => $agg['final_amount'],
                ];
            }

            // 5. Create the booking
            $booking = Booking::create([
                'user_id' => $client->id,
                'pricing_plan_id' => $data['pricing_plan_id'] ?? null,
                'referral_code_id' => $data['referral_code_id'] ?? null,
                'title' => $data['campaign_title'],
                'campaign_title' => $data['campaign_title'],
                'advertiser_name' => $client->name,
                'advertiser_email' => $client->email,
                'advertiser_phone' => $client->phone,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'ad_frequency' => $data['ad_frequency'] ?? 'every_5_min',
                'price' => $authoritativeSubtotal,
                'subtotal' => $authoritativeSubtotal,
                'discount_amount' => $authoritativeDiscount,
                'referral_discount' => $authoritativeReferral,
                'reward_points_earned' => 0,
                'reward_points_redeemed' => $rewardPointsRedeemed,
                'reward_discount_amount' => $rewardDiscountAmount,
                'final_amount' => $authoritativeFinal,
                'total_screens' => $data['total_screens'] ?? count((array) ($data['screen_ids'] ?? [])),
                'status' => $initialStatus,
                'notes' => $data['notes'] ?? null,
                'sales_notes' => $data['sales_notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'payment_mode' => $data['payment_mode'] ?? null,
                'offline_payment_reference' => $data['offline_payment_reference'] ?? null,
                'source' => $data['source'] ?? ($partnerId ? Booking::SOURCE_PARTNER : Booking::SOURCE_ADMIN),
                'booking_source' => $data['booking_source'] ?? ($partnerId ? 'partner_portal' : 'admin_manual'),
                'created_by_admin_id' => $partnerId ? null : $admin->id,
                'created_by_partner_id' => $partnerId,
                'created_by_user_id' => $admin->id,
                'pricing_snapshot' => $pricingSnapshot,
                'advertisement_category_id' => $data['advertisement_category_id'] ?? null,
                'advertisement_category_name' => isset($data['advertisement_category_id']) && $data['advertisement_category_id'] 
                    ? \Illuminate\Support\Facades\DB::table('advertisement_categories')
                        ->where('id', $data['advertisement_category_id'])
                        ->value('name') 
                    : null,
            ]);

            // 5b. If user redeemed points, immediately debit them from account
            if ($rewardPointsRedeemed > 0) {
                app(\App\Services\Campaign\LoyaltyService::class)->debitRedeemedPoints($booking, $rewardPointsRedeemed);
            }

            // 6. Log activity
            BookingActivity::create([
                'booking_id' => $booking->id,
                'type' => 'created',
                'label' => 'Manual Booking Created',
                'description' => sprintf(
                    'Campaign booking created manually by %s (source: %s)',
                    $admin->name,
                    $data['booking_source'] ?? 'admin_manual'
                ),
                'performed_by' => $admin->id,
            ]);

            // 7. Create booking_screen records with ownership snapshot
            $uniqueScreenIds = array_values(array_unique(array_filter((array) $data['screen_ids'])));
            foreach ($uniqueScreenIds as $screenId) {
                $screen = Screen::with(['city', 'screenProvider'])->find($screenId);
                if (!$screen) {
                    $screen = Screen::first();
                }
                if ($screen) {
                    $screensForRevenue[$screenId] = $screen;

                    $screenCount = count($uniqueScreenIds);
                    $screenQty = max(1, (int) ($data['quantities'][$screenId] ?? 1));
                    $screenData = [
                        'booking_id' => $booking->id,
                        'screen_id' => $screen->id,
                        'city_id' => $screen->city_id ?? null,
                        'quantity' => $screenQty,
                        'subtotal' => $screenCount > 0 ? ($authoritativeSubtotal / $screenCount) : $authoritativeSubtotal,
                        'screen_category_id' => $screen->screen_category_id,
                        'screen_category_name' => $screen->screenCategory?->name ?? $screen->screen_type,
                        'pricing_snapshot' => [
                            'screen_name' => $screen->name,
                            'screen_code' => $screen->screen_code,
                            'location' => $screen->location_name,
                            'city' => $screen->city->name ?? null,
                            'screen_category_id' => $screen->screen_category_id,
                            'screen_category_name' => $screen->screenCategory?->name ?? $screen->screen_type,
                        ],
                        'status' => 'pending',
                        // Ownership snapshot
                        'owner_type' => $screen->owner_type ?? Screen::OWNER_TYPE_ILLUVISION,
                        'screen_provider_id' => $screen->screen_provider_id,
                        'screen_provider_name' => $screen->screenProvider?->provider_name,
                    ];

                    // Capture revenue split percentages if owned by a provider
                    if ($screen->isOwnedByProvider() && $screen->screenProvider) {
                        $plan = $screen->relationLoaded('pricingPlan') ? $screen->pricingPlan : $screen->pricingPlan()->first();
                        $durationRule = null;
                        if (!empty($pricingValidation['calculated']['per_screen_breakdowns'])) {
                            foreach ($pricingValidation['calculated']['per_screen_breakdowns'] as $psb) {
                                if ((int) ($psb['screen_id'] ?? 0) === (int) $screen->id && !empty($psb['applied_discount_rule']['id'])) {
                                    $durationRule = \App\Models\PricingDurationRule::find($psb['applied_discount_rule']['id']);
                                    break;
                                }
                            }
                        }

                        $splitResult = $this->splitResolver->resolveEffectiveSplit($screen, $plan, $durationRule);
                        $screenData['illuvision_percentage'] = $splitResult['illuvision_percentage'];
                        $screenData['screen_provider_percentage'] = $splitResult['screen_provider_percentage'];
                        $screenData['gross_revenue'] = $screenCount > 0 ? ($authoritativeFinal / $screenCount) : $authoritativeFinal;
                    }

                    BookingScreen::create($screenData);
                }
            }

            // 8. Process revenue distribution snapshots
            $this->distributeBookingRevenue->execute($booking);

            // 8. Store pricing breakdowns
            if (!empty($pricingValidation['calculated'])) {
                $this->campaignPricingService->storePricingBreakdowns(
                    $booking,
                    $pricingValidation['calculated']
                );

                // Save first booking discount fields to the booking for reporting
                $fbdData = $pricingValidation['calculated']['first_booking_discount'] ?? [];
                if (!empty($fbdData['eligible'])) {
                    $booking->update([
                        'first_booking_discount_applied' => true,
                        'first_booking_discount_amount' => $fbdData['amount'] ?? 0,
                    ]);
                }
            }

            // 9. If offline payment, create a payment record
            if ($hasOfflinePayment) {
                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'amount' => $authoritativeFinal,
                    'currency' => 'INR',
                    'payment_status' => Payment::STATUS_PAID,
                    'payment_method' => $data['payment_mode'],
                    'paid_at' => now(),
                    'metadata' => [
                        'recorded_by' => $admin->id,
                        'recorded_by_name' => $admin->name,
                        'offline_payment_reference' => $data['offline_payment_reference'] ?? null,
                        'booking_source' => $data['booking_source'] ?? 'admin_manual',
                        'notes' => $data['sales_notes'] ?? '',
                    ],
                ]);

                BookingActivity::create([
                    'booking_id' => $booking->id,
                    'type' => 'payment_completed',
                    'label' => 'Offline Payment Recorded',
                    'description' => sprintf(
                        'Offline payment of ₹%s via %s recorded by %s',
                        number_format($authoritativeFinal, 2),
                        $data['payment_mode'],
                        $admin->name
                    ),
                    'metadata' => [
                        'payment_id' => $payment->id,
                        'payment_mode' => $data['payment_mode'],
                        'reference' => $data['offline_payment_reference'] ?? null,
                    ],
                    'performed_by' => $admin->id,
                ]);

                // Handle referral tracking if applicable
                if ($booking->referral_code_id) {
                    $referralCode = \App\Models\ReferralCode::find($booking->referral_code_id);
                    if ($referralCode) {
                        try {
                            $referralResult = $this->recordReferralAttribution->execute($booking, $payment->id, $referralCode);

                            if ($referralResult['usage'] || $referralResult['commission']) {
                                BookingActivity::create([
                                    'booking_id' => $booking->id,
                                    'type' => 'referral_tracking',
                                    'label' => 'Referral Tracked',
                                    'description' => sprintf(
                                        'Referral usage #%s created with commission ₹%s',
                                        $referralResult['usage']?->id ?? 'N/A',
                                        number_format($referralResult['commission']?->amount ?? 0, 2)
                                    ),
                                    'performed_by' => $admin->id,
                                ]);
                            }
                        } catch (\Exception $e) {
                            // Log but don't fail booking creation
                            \Illuminate\Support\Facades\Log::warning('Referral tracking failed for manual booking', [
                                'booking_id' => $booking->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // 10. If bypass_approval, log approval and award rewards immediately
            if ($bypassApproval) {
                $booking->update([
                    'approved_at' => now(),
                    'approved_by' => $admin->id,
                ]);

                BookingActivity::create([
                    'booking_id' => $booking->id,
                    'type' => 'booking_confirmed',
                    'label' => 'Campaign Auto-Approved',
                    'description' => 'Campaign auto-approved by ' . $admin->name . ' (bypass approval flow)',
                    'performed_by' => $admin->id,
                ]);

                // Since the booking was created with 'approved' status (no status transition),
                // the model's updated event won't fire for a status change.
                // Award rewards explicitly via the configured trigger.
                $settings = app(\App\Services\SettingsService::class);
                $trigger = $settings->get('loyalty', 'reward_trigger', 'booking_approved');
                $knownTriggers = ['payment_successful', 'booking_approved', 'campaign_completed'];
                $effectiveTrigger = in_array($trigger, $knownTriggers, true) ? $trigger : 'booking_approved';
                if ($effectiveTrigger === 'booking_approved') {
                    app(\App\Services\Campaign\LoyaltyService::class)->creditEarnedPoints($booking);
                }
            }

            $booking->load([
                'user',
                'bookingScreens.screen.city',
                'bookingScreens.city',
                'bookingMedia',
                'payments',
                'pricingPlan',
                'referralCode.partner',
                'createdByAdmin',
            ]);

            return $booking;
        });
    }

    /**
     * Upload media for a manual booking.
     *
     * @param Booking $booking
     * @param UploadedFile[] $files
     * @param User $admin
     * @return array
     */
    public function uploadMedia(Booking $booking, array $files, User $admin): array
    {
        $uploaded = [];

        foreach ($files as $index => $file) {
            $mediaData = $this->mediaService->upload($file, 'bookings/' . $booking->id);

            $media = BookingMedia::create([
                'booking_id' => $booking->id,
                'file_path' => $mediaData['file_path'],
                'file_type' => $mediaData['file_type'],
                'file_size' => $mediaData['file_size'],
                'mime_type' => $mediaData['mime_type'],
                'original_name' => $mediaData['original_name'],
                'sort_order' => $index,
            ]);

            $uploaded[] = array_merge($media->toArray(), [
                'url' => $mediaData['url'],
            ]);
        }

        BookingActivity::create([
            'booking_id' => $booking->id,
            'type' => 'media_uploaded',
            'label' => 'Media Uploaded',
            'description' => count($uploaded) . ' media file(s) uploaded by ' . $admin->name,
            'performed_by' => $admin->id,
        ]);

        return $uploaded;
    }

    /**
     * Record an offline/manual payment for a booking.
     *
     * @param Booking $booking
     * @param array $data  { payment_mode, amount, reference?, notes? }
     * @param User $admin
     * @return Payment
     */
    public function recordOfflinePayment(Booking $booking, array $data, User $admin): Payment
    {
        return DB::transaction(function () use ($booking, $data, $admin) {
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'amount' => $data['amount'],
                'currency' => 'INR',
                'payment_status' => Payment::STATUS_PAID,
                'payment_method' => $data['payment_mode'],
                'paid_at' => now(),
                'metadata' => [
                    'recorded_by' => $admin->id,
                    'recorded_by_name' => $admin->name,
                    'offline_payment_reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? '',
                ],
            ]);

            // Update booking payment_mode if not already set
            if (!$booking->payment_mode) {
                $booking->update([
                    'payment_mode' => $data['payment_mode'],
                    'offline_payment_reference' => $data['reference'] ?? $booking->offline_payment_reference,
                ]);
            }

            // Move booking status forward if still in payment pending
            if ($booking->status === Booking::STATUS_PAYMENT_PENDING) {
                $booking->update(['status' => Booking::STATUS_PENDING_APPROVAL]);
            }

            // Handle referral tracking if applicable
            if ($booking->referral_code_id) {
                try {
                    $referralCode = \App\Models\ReferralCode::find($booking->referral_code_id);
                    if ($referralCode) {
                        $this->recordReferralAttribution->execute($booking, $payment->id, $referralCode);
                    }
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Referral tracking failed for offline payment', [
                        'booking_id' => $booking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            BookingActivity::create([
                'booking_id' => $booking->id,
                'type' => 'payment_completed',
                'label' => 'Offline Payment Recorded',
                'description' => sprintf(
                    'Offline payment of ₹%s via %s recorded by %s',
                    number_format($data['amount'], 2),
                    $data['payment_mode'],
                    $admin->name
                ),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_mode' => $data['payment_mode'],
                    'reference' => $data['reference'] ?? null,
                ],
                'performed_by' => $admin->id,
            ]);

            // Dispatch notification event
            event(new PaymentReceived($booking, $payment));

            return $payment->fresh(['booking']);
        });
    }
}
