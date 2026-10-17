<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected static function booted()
    {
        static::addGlobalScope('exclude_payment_pending', function ($builder) {
            // Bypass if querying by ID directly (find, findOrFail, route binding)
            foreach ($builder->getQuery()->wheres as $where) {
                if (isset($where['column']) && in_array($where['column'], ['id', 'bookings.id'])) {
                    if (isset($where['type']) && $where['type'] === 'Basic') {
                        return;
                    }
                }
            }

            // Bypass for payment/checkout endpoints
            $route = request()->route();
            if ($route) {
                $actionName = $route->getActionName();
                if (str_contains($actionName, 'PaymentController') ||
                    str_contains($actionName, 'AdminPaymentController') ||
                    str_contains($actionName, 'AdminManualBookingController') ||
                    str_contains($actionName, 'campaignDetails') ||
                    str_contains($actionName, 'uploadMedia') ||
                    str_contains($actionName, 'storeCampaign')
                ) {
                    return;
                }
            }

            $builder->where('bookings.status', '!=', self::STATUS_PAYMENT_PENDING);
        });

        static::updated(function (Booking $booking) {
            $oldStatus = $booking->getOriginal('status');
            $newStatus = $booking->status;

            if ($oldStatus !== $newStatus) {
                $loyaltyService = app(\App\Services\Campaign\LoyaltyService::class);
                $settings = app(\App\Services\SettingsService::class);
                $trigger = $settings->get('loyalty', 'reward_trigger', 'booking_approved');

                // Award reward points when the configured trigger event occurs.
                // reward_trigger options: 'payment_successful', 'booking_approved', 'campaign_completed'
                // Default: 'booking_approved'
                $knownTriggers = ['payment_successful', 'booking_approved', 'campaign_completed'];
                $isValidTrigger = in_array($trigger, $knownTriggers, true);

                // Default to 'booking_approved' behavior for unrecognized or empty triggers
                $effectiveTrigger = $isValidTrigger ? $trigger : 'booking_approved';

                if ($newStatus === self::STATUS_APPROVED && $effectiveTrigger === 'booking_approved') {
                    $loyaltyService->creditEarnedPoints($booking);
                }

                if ($newStatus === self::STATUS_COMPLETED && $effectiveTrigger === 'campaign_completed') {
                    $loyaltyService->creditEarnedPoints($booking);
                }

                // payment_successful trigger is not handled via model events;
                // it must be invoked explicitly from the payment verification flow.

                if ($newStatus === self::STATUS_CANCELLED || $newStatus === self::STATUS_REJECTED) {
                    $loyaltyService->reverseEarnedPoints($booking);
                    $loyaltyService->refundRedeemedPoints($booking);
                    app(\App\Services\Campaign\ReferralTrackingService::class)->cancelReferralTracking($booking);
                }

                if ($newStatus === self::STATUS_REFUNDED) {
                    $loyaltyService->reverseEarnedPoints($booking);
                    $loyaltyService->refundRedeemedPoints($booking);
                    app(\App\Services\Campaign\ReferralTrackingService::class)->refundReferralTracking($booking);
                }
            }
        });

        static::deleting(function (Booking $booking) {
            $loyaltyService = app(\App\Services\Campaign\LoyaltyService::class);
            $loyaltyService->refundRedeemedPoints($booking);
        });
    }

    protected $fillable = [
        'user_id',
        'screen_id',
        'pricing_plan_id',
        'referral_code_id',
        'booking_reference',
        'title',
        'campaign_title',
        'advertiser_name',
        'advertiser_email',
        'advertiser_phone',
        'start_time',
        'end_time',
        'price',
        'total_price',
        'subtotal',
        'discount_amount',
        'referral_discount',
        'first_booking_discount_applied',
        'first_booking_discount_amount',
        'final_amount',
        'ad_frequency',
        'total_screens',
        'status',
        'rejection_reason',
        'approved_at',
        'approved_by',
        'notes',
        'source',
        'booking_source',
        'created_by_admin_id',
        'created_by_partner_id',
        'created_by_user_id',
        'sales_notes',
        'internal_notes',
        'payment_mode',
        'offline_payment_reference',
        'session_started_at',
        'session_ended_at',
        'payment_due_at',
        'pricing_snapshot',
        'advertisement_category_id',
        'advertisement_category_name',
        'reward_points_earned',
        'reward_points_redeemed',
        'reward_discount_amount',
        // Display currency fields — the visitor's selected display currency at booking time
        'currency_code',
        'exchange_rate_used',
        'display_amount',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'referral_discount' => 'decimal:2',
        'first_booking_discount_amount' => 'decimal:2',
        'first_booking_discount_applied' => 'boolean',
        'final_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'session_started_at' => 'datetime',
        'session_ended_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'pricing_snapshot' => 'array',
        'reward_points_earned' => 'integer',
        'reward_points_redeemed' => 'integer',
        'reward_discount_amount' => 'decimal:2',
    ];

    protected $appends = [
        'duration_minutes',
        'duration_days',
        'duration_label',
    ];

    /**
     * Canonical duration in minutes derived from start_time and end_time.
     */
    public function getDurationMinutesAttribute(): int
    {
        if ($this->start_time && $this->end_time) {
            return \App\Support\DurationFormatter::calculateMinutes($this->start_time, $this->end_time);
        }
        return 0;
    }

    /**
     * Compatibility duration in days.
     */
    public function getDurationDaysAttribute(): int|float
    {
        $minutes = $this->duration_minutes;
        if ($minutes <= 0) {
            return 0;
        }
        if ($minutes >= 1440) {
            return (int) round($minutes / 1440);
        }
        return round($minutes / 1440, 4);
    }

    /**
     * Human-readable formatted duration (e.g. "30 Minutes", "1 Hour 30 Minutes", "2 Days").
     */
    public function getDurationLabelAttribute(): string
    {
        $minutes = $this->duration_minutes;
        if ($minutes <= 0) {
            return '—';
        }
        return \App\Support\DurationFormatter::formatMinutes($minutes);
    }

    // ─── Status Constants ───────────────────────────────

    const STATUS_DRAFT = 'draft';
    const STATUS_PAYMENT_PENDING = 'payment_pending';
    const STATUS_PAYMENT_FAILED = 'payment_failed';
    const STATUS_MEDIA_REVIEW_PENDING = 'media_review_pending';
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REFUNDED = 'refunded';

    // ─── Booking Source Constants ───────────────────────

    const SOURCE_CUSTOMER = 'customer';
    const SOURCE_ADMIN = 'admin';
    const SOURCE_PARTNER = 'partner';
    const SOURCE_API = 'api';
    const SOURCE_WHATSAPP = 'whatsapp';
    const SOURCE_WEBSITE = 'website';
    const SOURCE_EMAIL = 'email';
    const SOURCE_CALL = 'call';
    const SOURCE_OFFLINE_SALES = 'offline_sales';
    const SOURCE_ADMIN_MANUAL = 'admin_manual';

    // ─── Relationships ───────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdByPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'created_by_partner_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rewardRedemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function rewardTransactions(): HasMany
    {
        return $this->hasMany(RewardTransaction::class);
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class)->withTrashed();
    }

    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function advertisementCategory(): BelongsTo
    {
        return $this->belongsTo(AdvertisementCategory::class, 'advertisement_category_id');
    }

    public function bookingScreens(): HasMany
    {
        return $this->hasMany(BookingScreen::class);
    }

    public function screens(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Screen::class, 'booking_screens', 'booking_id', 'screen_id')
            ->withPivot(['city_id', 'subtotal', 'pricing_snapshot', 'status', 'owner_type', 'screen_provider_id'])
            ->withTimestamps();
    }
    public function referralUsages() { return $this->hasMany(ReferralUsage::class); }

    public function bookingMedia(): HasMany
    {
        return $this->hasMany(BookingMedia::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function bookingActivities(): HasMany
    {
        return $this->hasMany(BookingActivity::class)->latest('created_at');
    }

    // ─── Scopes ────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_SCHEDULED,
            self::STATUS_RUNNING,
        ]);
    }

    public function scopePendingCampaignReview($query)
    {
        return $query->whereIn('status', [
            self::STATUS_MEDIA_REVIEW_PENDING,
            self::STATUS_PENDING_APPROVAL,
        ]);
    }

    public function scopeNeedsMediaReview($query)
    {
        return $query->where('status', self::STATUS_MEDIA_REVIEW_PENDING);
    }

    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByPaymentStatus($query, string $paymentStatus)
    {
        // payment status is derived from the latest payment record
        return $query->whereHas('payments', function ($q) use ($paymentStatus) {
            $q->where('payment_status', $paymentStatus);
        });
    }

    public function scopeRunningNow($query)
    {
        return $query->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->where('status', self::STATUS_RUNNING);
    }

    /**
     * Compile a detailed reward points summary for this booking.
     */
    public function getLoyaltySummary(): array
    {
        if (!$this->relationLoaded('rewardTransactions')) {
            $this->load('rewardTransactions');
        }
        if (!$this->relationLoaded('rewardRedemptions')) {
            $this->load('rewardRedemptions');
        }

        $loyaltyService = app(\App\Services\Campaign\LoyaltyService::class);
        $settingsService = app(\App\Services\SettingsService::class);

        $earnTx = $this->rewardTransactions->where('type', RewardTransaction::TYPE_EARN)->first();
        $redeemTx = $this->rewardTransactions->where('type', RewardTransaction::TYPE_REDEEM)->first();
        $reversalTx = $this->rewardTransactions->where('type', RewardTransaction::TYPE_REFUND_REVERSAL)->first();

        // Determine reward status
        $status = 'not_eligible';
        if ($this->status === self::STATUS_CANCELLED || $this->status === self::STATUS_REFUNDED) {
            if ($reversalTx || $this->reward_points_earned > 0 || $this->reward_points_redeemed > 0) {
                $status = 'reversed';
            }
        } elseif (in_array($this->status, [self::STATUS_PENDING_APPROVAL, self::STATUS_MEDIA_REVIEW_PENDING, self::STATUS_DRAFT, self::STATUS_PAYMENT_PENDING])) {
            if ($this->reward_points_redeemed > 0) {
                $status = 'redeemed';
            } elseif ($loyaltyService->calculatePointsToEarn($this) > 0) {
                $status = 'pending';
            }
        } else { // approved, scheduled, running, completed
            if ($earnTx) {
                $status = 'awarded';
            } elseif ($this->reward_points_redeemed > 0) {
                $status = 'redeemed';
            } elseif ($loyaltyService->calculatePointsToEarn($this) > 0) {
                $status = 'pending';
            }
        }

        $rewardAccount = RewardAccount::where('user_id', $this->user_id)->first();

        return [
            'reward_status' => $status,
            'points_earned' => (int) ($this->reward_points_earned ?: 0),
            'points_redeemed' => (int) ($this->reward_points_redeemed ?: 0),
            'reward_discount_amount' => (float) ($this->reward_discount_amount ?: 0),
            'reward_transaction_id' => $earnTx?->id,
            'reward_redemption_id' => $redeemTx?->id,
            'reward_trigger_status' => $settingsService->get('loyalty', 'reward_trigger', 'booking_approved'),
            'processed_at' => $earnTx?->created_at?->toIso8601String() ?? $redeemTx?->created_at?->toIso8601String(),
            'customer_current_balance' => (int) ($rewardAccount?->balance ?? 0),
            'settings_snapshot' => [
                'points_expiry_days' => (int) $settingsService->get('loyalty', 'points_expiry_days', 365),
                'redemption_point_value' => (float) $settingsService->get('loyalty', 'redemption_point_value', 1.00),
                'spend_required_per_point' => (int) $settingsService->get('loyalty', 'spend_required_per_point', 100),
            ]
        ];
    }
}