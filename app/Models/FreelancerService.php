<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreelancerService extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'freelancer_profile_id', 'title', 'slug', 'description', 'category', 'category_id', 'subcategory', 'skills', 'highlights',
        'featured_image', 'gallery', 'video_url', 'video_path', 'video_thumbnail', 'video_status',
        'status', 'is_paused', 'paused_at', 'pause_reason',
        'moderation_status', 'moderation_feedback', 'moderated_at', 'moderated_by',
    ];

    protected $casts = [
        'skills' => 'array',
        'highlights' => 'array',
        'gallery' => 'array',
        'rating' => 'decimal:2',
        'is_paused' => 'boolean',
        'paused_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
    public function categoryModel(): BelongsTo { return $this->belongsTo(FreelanceCategory::class, 'category_id'); }
    public function packages(): HasMany { return $this->hasMany(FreelancerServicePackage::class); }
}
