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
        'languages', 'availability', 'response_time', 'seller_level', 'social_links', 'verification_status',
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'languages' => 'array',
        'social_links' => 'array',
        'rating' => 'decimal:2',
        'total_earnings' => 'decimal:2',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function skills(): HasMany { return $this->hasMany(FreelancerSkill::class); }
    public function services(): HasMany { return $this->hasMany(FreelancerService::class); }
    public function portfolioItems(): HasMany { return $this->hasMany(PortfolioItem::class); }
    public function wallet(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(FreelancerWallet::class); }
    public function withdrawals(): HasMany { return $this->hasMany(FreelancerWithdrawal::class); }
    public function projects(): HasMany { return $this->hasMany(FreelanceProject::class); }
    public function reviews(): HasMany { return $this->hasMany(FreelanceReview::class); }
}
