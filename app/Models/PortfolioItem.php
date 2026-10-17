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
        'title', 'description', 'category', 'project_url', 'skills',
        'completed_at', 'visibility', 'status', 'is_featured',
    ];

    protected $casts = ['skills' => 'array', 'completed_at' => 'date', 'is_featured' => 'boolean'];

    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
    public function media(): HasMany { return $this->hasMany(PortfolioMedia::class); }
}
