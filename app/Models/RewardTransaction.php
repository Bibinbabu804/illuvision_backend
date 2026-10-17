<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RewardTransaction extends Model
{
    use HasFactory;

    const TYPE_EARN = 'earn';
    const TYPE_REDEEM = 'redeem';
    const TYPE_EXPIRE = 'expire';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_REFUND_REVERSAL = 'refund_reversal';
    const TYPE_ADMIN_BONUS = 'admin_bonus';

    protected $fillable = [
        'reward_account_id',
        'booking_id',
        'type',
        'points',
        'points_used',
        'description',
        'reason',
        'admin_id',
        'expiry_date',
    ];

    protected $casts = [
        'points' => 'integer',
        'points_used' => 'integer',
        'expiry_date' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(RewardAccount::class, 'reward_account_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function redemptions()
    {
        return $this->hasMany(RewardRedemption::class);
    }
}
