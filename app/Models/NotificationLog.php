<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'event',
        'recipient_type',
        'recipient_email',
        'recipient_id',
        'channel',
        'status',
        'failed_reason',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Scope by event name.
     */
    public function scopeEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    /**
     * Scope by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope only failed notifications.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
