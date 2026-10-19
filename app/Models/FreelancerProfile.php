<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreelancerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'professional_title', 'bio', 'location', 'hourly_rate', 'experience_years',
        'languages', 'availability', 'response_time', 'response_time_seconds', 'seller_level', 'social_links', 'verification_status',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'languages' => 'array',
        'social_links' => 'array',
        'rating' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'response_time_seconds' => 'integer',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function skills(): HasMany { return $this->hasMany(FreelancerSkill::class); }
    public function services(): HasMany { return $this->hasMany(FreelancerService::class); }
    public function portfolioItems(): HasMany { return $this->hasMany(PortfolioItem::class); }
    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(FreelancerWallet::class); }
    public function withdrawals(): HasMany { return $this->hasMany(FreelancerWithdrawal::class); }
    public function projects(): HasMany { return $this->hasMany(FreelanceProject::class); }
    public function reviews(): HasMany { return $this->hasMany(FreelanceReview::class); }
    public function unavailabilities(): HasMany { return $this->hasMany(FreelancerUnavailability::class); }
    public function profileMedia(): HasMany { return $this->hasMany(FreelancerProfileMedia::class)->orderBy('sort_order'); }
    public function reviewReplies(): HasMany { return $this->hasMany(FreelanceReviewReply::class); }
    public function categoryRequests(): HasMany { return $this->hasMany(FreelanceCategoryRequest::class); }

    public function activeUnavailability(): ?FreelancerUnavailability
    {
        $today = now()->format('Y-m-d');
        return $this->unavailabilities()
            ->where('from_date', '<=', $today)
            ->where('to_date', '>=', $today)
            ->first();
    }
}
