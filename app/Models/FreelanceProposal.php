<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelanceProposal extends Model { protected $fillable=['project_id','freelancer_profile_id','amount','delivery_days','revisions','description','status','expires_at']; protected $casts=['amount'=>'decimal:2','expires_at'=>'datetime']; public function project():BelongsTo{return $this->belongsTo(FreelanceProject::class,'project_id');} public function freelancerProfile():BelongsTo{return $this->belongsTo(FreelancerProfile::class);}}
