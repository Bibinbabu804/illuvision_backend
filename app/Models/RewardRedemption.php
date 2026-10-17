<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RewardRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'reward_transaction_id',
        'points_redeemed',
        'discount_amount',
    ];

    protected $casts = [
        'points_redeemed' => 'integer',
        'discount_amount' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function transaction()
    {
        return $this->belongsTo(RewardTransaction::class, 'reward_transaction_id');
    }
}
