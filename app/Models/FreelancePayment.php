<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelancePayment extends Model { protected $fillable=['project_id','client_id','gateway','gateway_order_id','gateway_payment_id','gateway_signature','amount','currency','status','held_at','released_at','refunded_at','metadata']; protected $casts=['amount'=>'decimal:2','metadata'=>'array','held_at'=>'datetime','released_at'=>'datetime','refunded_at'=>'datetime']; public function project():BelongsTo{return $this->belongsTo(FreelanceProject::class,'project_id');} public function client():BelongsTo{return $this->belongsTo(User::class,'client_id');}}
