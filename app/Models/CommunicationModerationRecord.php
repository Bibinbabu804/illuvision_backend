<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationModerationRecord extends Model
{
    protected $fillable = [
        'user_id',
        'context_type',
        'context_id',
        'detected_triggers',
        'original_text',
        'sanitized_text',
        'severity',
        'status',
        'admin_notes',
    ];

    protected $casts = [
        'detected_triggers' => 'array',
        'context_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
