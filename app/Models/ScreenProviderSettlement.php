<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenProviderSettlement extends Model
{
    use HasFactory;

    protected $table = 'screen_provider_settlements';

    const STATUS_DRAFT = 'draft';
    const STATUS_GENERATED = 'generated';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_GENERATED => 'Generated',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PAID => 'Paid',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    protected $fillable = [
        'screen_provider_id',
        'period_start',
        'period_end',
        'gross_revenue',
        'platform_revenue',
        'provider_revenue',
        'booking_count',
        'screen_count',
        'status',
        'reference_number',
        'generated_by',
        'approved_by',
        'paid_by',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'gross_revenue' => 'decimal:2',
        'platform_revenue' => 'decimal:2',
        'provider_revenue' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function screenProvider(): BelongsTo
    {
        return $this->belongsTo(ScreenProvider::class, 'screen_provider_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function bookingScreens(): HasMany
    {
        return $this->hasMany(BookingScreen::class, 'settlement_id');
    }

    public function scopeStatus($query, ?string $status)
    {
        if ($status) {
            return $query->where('status', $status);
        }
        return $query;
    }

    public function scopeScreenProviderId($query, ?int $providerId)
    {
        if ($providerId) {
            return $query->where('screen_provider_id', $providerId);
        }
        return $query;
    }

    public function scopePeriodBetween($query, ?string $start, ?string $end)
    {
        if ($start) {
            $query->where('period_end', '>=', $start);
        }
        if ($end) {
            $query->where('period_start', '<=', $end);
        }
        return $query;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_GENERATED]);
    }

    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_GENERATED;
    }

    public function canBePaid(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, [self::STATUS_PAID, self::STATUS_CANCELLED]);
    }
}
