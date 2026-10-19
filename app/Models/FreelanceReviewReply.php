<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelanceReviewReply extends Model
{
    protected $fillable = [
        'review_id',
        'freelancer_profile_id',
        'reply',
        'status',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(FreelanceReview::class, 'review_id');
    }

    public function freelancerProfile(): BelongsTo
    {
        return $this->belongsTo(FreelancerProfile::class, 'freelancer_profile_id');
    }
}
