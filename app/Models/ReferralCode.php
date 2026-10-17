<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReferralCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'code',
        'status',
        'start_date',
        'expiry_date',
        'usage_limit',
        'used_count',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'commission_type',
        'commission_value',
        'max_commission_amount',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expiry_date' => 'date',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'discount_value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'max_commission_amount' => 'decimal:2',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function usages()
    {
        return $this->hasMany(ReferralUsage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeValid($query)
    {
        return $query->where('status', 'active')
            ->where('start_date', '<=', now())
            ->where('expiry_date', '>=', now())
            ->where(function ($q) {
                $q->where('usage_limit', 0)
                  ->orWhereColumn('used_count', '<', 'usage_limit');
            });
    }
}
