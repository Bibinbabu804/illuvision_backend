<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelancerWithdrawal extends Model { protected $fillable=['freelancer_profile_id','amount','payment_method','account_reference','status','admin_note','requested_at','processed_at']; protected $casts=['amount'=>'decimal:2','requested_at'=>'datetime','processed_at'=>'datetime']; public function freelancerProfile():BelongsTo{return $this->belongsTo(FreelancerProfile::class);}}
