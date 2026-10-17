<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ScreenCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'sort_order',
    ];

    protected static function booted()
    {
        static::creating(function (ScreenCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class, 'screen_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
