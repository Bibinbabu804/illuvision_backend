<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'ideal_for',
        'thumbnail',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'ideal_for' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function enquiries(): HasMany
    {
        return $this->hasMany(ProductEnquiry::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }
}
