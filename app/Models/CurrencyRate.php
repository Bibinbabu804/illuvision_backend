<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    protected $fillable = [
        'currency_code',
        'exchange_rate',
        'base_currency',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'fetched_at'    => 'datetime',
    ];

    public function scopeForCurrency($query, string $currencyCode)
    {
        return $query->where('currency_code', strtoupper($currencyCode))
                     ->where('base_currency', 'INR');
    }

    public function isStale(): bool
    {
        return $this->fetched_at === null || $this->fetched_at->diffInHours(now()) >= 12;
    }
}
