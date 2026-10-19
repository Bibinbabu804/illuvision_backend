<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelanceProjectMilestone extends Model
{
    protected $fillable = [
        'project_id',
        'milestone_number',
        'title',
        'description',
        'percentage',
        'gross_amount',
        'freelancer_amount',
        'platform_fee',
        'status',
        'deliverable_id',
        'submitted_at',
        'approved_at',
        'released_at',
        'idempotency_key',
    ];

    protected $casts = [
        'milestone_number' => 'integer',
        'percentage' => 'decimal:2',
        'gross_amount' => 'decimal:2',
        'freelancer_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(FreelanceDeliverable::class, 'deliverable_id');
    }

    public function getFreelancerNetAmountAttribute(): float
    {
        return (float) $this->freelancer_amount;
    }
}
