<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FreelanceReview extends Model {
    protected $fillable = ['project_id', 'client_id', 'freelancer_profile_id', 'rating', 'review', 'status'];

    public function project(): BelongsTo { return $this->belongsTo(FreelanceProject::class, 'project_id'); }
    public function client(): BelongsTo { return $this->belongsTo(User::class, 'client_id'); }
    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
    public function reply(): HasOne { return $this->hasOne(FreelanceReviewReply::class, 'review_id'); }
}
