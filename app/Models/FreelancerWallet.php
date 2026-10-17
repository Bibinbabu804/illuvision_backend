<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FreelancerWallet extends Model { protected $fillable=['freelancer_profile_id','available_balance','pending_balance','lifetime_earnings']; protected $casts=['available_balance'=>'decimal:2','pending_balance'=>'decimal:2','lifetime_earnings'=>'decimal:2']; public function freelancerProfile():BelongsTo{return $this->belongsTo(FreelancerProfile::class);} public function transactions():HasMany{return $this->hasMany(FreelancerWalletTransaction::class,'wallet_id');}}
