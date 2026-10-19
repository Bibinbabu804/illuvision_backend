<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelanceCategoryRequest extends Model
{
    protected $fillable = [
        'user_id',
        'freelancer_profile_id',
        'name',
        'type',
        'parent_id',
        'suggested_description',
        'status',
        'admin_feedback',
        'reviewed_by',
        'reviewed_at',
        'created_category_id',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function freelancerProfile(): BelongsTo
    {
        return $this->belongsTo(FreelancerProfile::class, 'freelancer_profile_id');
    }

    public function parentCategory(): BelongsTo
    {
        return $this->belongsTo(FreelanceCategory::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FreelanceCategory::class, 'parent_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdCategory(): BelongsTo
    {
        return $this->belongsTo(FreelanceCategory::class, 'created_category_id');
    }
}
