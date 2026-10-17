<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenProvider extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING_REVIEW = 'pending_review';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_INACTIVE = 'inactive';

    const BUSINESS_TYPE_INDIVIDUAL = 'individual';
    const BUSINESS_TYPE_COMPANY = 'company';
    const BUSINESS_TYPE_MALL = 'mall';
    const BUSINESS_TYPE_ADVERTISING_AGENCY = 'advertising_agency';
    const BUSINESS_TYPE_DIGITAL_SIGNAGE_NETWORK = 'digital_signage_network';
    const BUSINESS_TYPE_OTHER = 'other';

    public static function businessTypes(): array
    {
        return [
            self::BUSINESS_TYPE_INDIVIDUAL => 'Individual',
            self::BUSINESS_TYPE_COMPANY => 'Company',
            self::BUSINESS_TYPE_MALL => 'Mall',
            self::BUSINESS_TYPE_ADVERTISING_AGENCY => 'Advertising Agency',
            self::BUSINESS_TYPE_DIGITAL_SIGNAGE_NETWORK => 'Digital Signage Network',
            self::BUSINESS_TYPE_OTHER => 'Other',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PENDING_REVIEW => 'Pending Review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_INACTIVE => 'Inactive',
        ];
    }

    protected $fillable = [
        'provider_name',
        'business_name',
        'contact_person',
        'phone',
        'email',
        'business_type',
        'status',
        'notes',
        'user_id',
        'illuvision_percentage',
        'screen_provider_percentage',
        'approved_at',
        'approved_by',
        'created_from_enquiry',
        'screen_provider_enquiry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'created_from_enquiry' => 'boolean',
            'illuvision_percentage' => 'decimal:2',
            'screen_provider_percentage' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(ScreenProviderEnquiry::class, 'screen_provider_enquiry_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ScreenProviderActivity::class, 'screen_provider_id');
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class, 'screen_provider_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING_REVIEW;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING_REVIEW);
    }
}
