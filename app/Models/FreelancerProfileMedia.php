<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerProfileMedia extends Model
{
    protected $fillable = [
        'freelancer_profile_id',
        'path',
        'original_name',
        'mime_type',
        'size',
        'is_primary',
        'sort_order',
        'moderation_status',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
        'size' => 'integer',
    ];

    public function freelancerProfile(): BelongsTo
    {
        return $this->belongsTo(FreelancerProfile::class, 'freelancer_profile_id');
    }
}
