<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'freelancer_profile_id', 'title', 'description', 'category', 'category_id', 'project_url', 'skills',
        'completed_at', 'visibility', 'status', 'is_featured',
        'moderation_status', 'moderation_feedback', 'moderated_at', 'moderated_by',
    ];

    protected $casts = [
        'skills' => 'array',
        'completed_at' => 'date',
        'is_featured' => 'boolean',
        'moderated_at' => 'datetime',
    ];

    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
    public function categoryModel(): BelongsTo { return $this->belongsTo(FreelanceCategory::class, 'category_id'); }
    public function media(): HasMany { return $this->hasMany(PortfolioMedia::class)->orderBy('sort_order'); }
}
