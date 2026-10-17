<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelanceDeliverable extends Model { protected $fillable=['project_id','submitted_by','title','message','revision_number','is_final','submitted_at']; protected $casts=['is_final'=>'boolean','submitted_at'=>'datetime']; public function project():BelongsTo{return $this->belongsTo(FreelanceProject::class,'project_id');} public function submitter():BelongsTo{return $this->belongsTo(User::class,'submitted_by');}}
