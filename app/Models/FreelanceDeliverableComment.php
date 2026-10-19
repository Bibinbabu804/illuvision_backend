<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelanceDeliverableComment extends Model
{
    protected $fillable = [
        'deliverable_id',
        'user_id',
        'comment',
        'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(FreelanceDeliverable::class, 'deliverable_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
