<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvertisementCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'status',
        'sort_order',
    ];

    /**
     * Get bookings in this category.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'advertisement_category_id');
    }
}
