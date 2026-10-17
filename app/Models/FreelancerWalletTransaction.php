<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelancerWalletTransaction extends Model { protected $fillable=['wallet_id','project_id','type','amount','balance_after','reference','description','metadata']; protected $casts=['amount'=>'decimal:2','balance_after'=>'decimal:2','metadata'=>'array']; public function wallet():BelongsTo{return $this->belongsTo(FreelancerWallet::class,'wallet_id');} public function project():BelongsTo{return $this->belongsTo(FreelanceProject::class,'project_id');}}
