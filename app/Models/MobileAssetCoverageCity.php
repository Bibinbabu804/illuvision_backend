<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAssetCoverageCity extends Model
{
    protected $table = 'mobile_asset_coverage_cities';

    protected $fillable = [
        'mobile_asset_id',
        'city_id',
    ];

    public function mobileAsset(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'mobile_asset_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
