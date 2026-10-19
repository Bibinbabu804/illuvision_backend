<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreelanceMessage extends Model {
    protected $fillable = [
        'conversation_id', 'sender_id', 'message_type', 'is_review_request',
        'milestone_id', 'deliverable_id', 'body', 'metadata', 'read_at',
    ];

    protected $casts = [
        'is_review_request' => 'boolean',
        'metadata' => 'array',
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo { return $this->belongsTo(FreelanceConversation::class, 'conversation_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_id'); }
    public function milestone(): BelongsTo { return $this->belongsTo(FreelanceProjectMilestone::class, 'milestone_id'); }
    public function deliverable(): BelongsTo { return $this->belongsTo(FreelanceDeliverable::class, 'deliverable_id'); }
    public function attachments(): HasMany { return $this->hasMany(FreelanceMessageAttachment::class, 'message_id'); }
}
