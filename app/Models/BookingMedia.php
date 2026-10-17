<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingMedia extends Model
{
    protected $table = 'booking_media';

    protected $fillable = [
        'booking_id',
        'file_path',
        'file_type',
        'file_size',
        'mime_type',
        'duration',
        'original_name',
        'sort_order',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'duration' => 'integer',
        'sort_order' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    // ─── Review Status Constants ──────────────────────────

    const REVIEW_PENDING = 'pending';
    const REVIEW_APPROVED = 'approved';
    const REVIEW_REJECTED = 'rejected';

    // ─── Relationships ────────────────────────────────────

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Scopes ───────────────────────────────────────────

    public function scopePendingReview($query)
    {
        return $query->where('review_status', self::REVIEW_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('review_status', self::REVIEW_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('review_status', self::REVIEW_REJECTED);
    }

    // ─── Helpers ──────────────────────────────────────────

    public function isPendingReview(): bool
    {
        return $this->review_status === self::REVIEW_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->review_status === self::REVIEW_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->review_status === self::REVIEW_REJECTED;
    }
}
