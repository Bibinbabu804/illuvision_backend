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
        'title', 'slug', 'description', 'category', 'subcategory', 'skills',
        'featured_image', 'gallery', 'status',
    ];

    protected $casts = [
        'skills' => 'array',
        'gallery' => 'array',
        'rating' => 'decimal:2',
    ];

    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
    public function packages(): HasMany { return $this->hasMany(FreelancerServicePackage::class); }
}
