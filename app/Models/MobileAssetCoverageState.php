<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAssetCoverageState extends Model
{
    protected $table = 'mobile_asset_coverage_states';

    protected $fillable = [
        'mobile_asset_id',
        'state_id',
    ];

    public function mobileAsset(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'mobile_asset_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }
}
