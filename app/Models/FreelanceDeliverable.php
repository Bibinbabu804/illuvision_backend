<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreelanceDeliverable extends Model {
    protected $fillable = [
        'project_id', 'milestone_id', 'submitted_by', 'title', 'message',
        'revision_number', 'is_final', 'status', 'submitted_at',
    ];

    protected $casts = [
        'is_final' => 'boolean',
        'revision_number' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function project(): BelongsTo { return $this->belongsTo(FreelanceProject::class, 'project_id'); }
    public function milestone(): BelongsTo { return $this->belongsTo(FreelanceProjectMilestone::class, 'milestone_id'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function comments(): HasMany { return $this->hasMany(FreelanceDeliverableComment::class, 'deliverable_id')->orderBy('created_at'); }
}
