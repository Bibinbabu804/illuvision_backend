<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverageCity extends Model
{
    protected $table = 'asset_coverage_cities';

    protected $fillable = [
        'asset_id',
        'country_id',
        'state_id',
        'city_id',
        'priority',
        'is_primary',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_primary' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'asset_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
