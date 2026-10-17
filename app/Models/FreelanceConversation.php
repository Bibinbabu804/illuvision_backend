<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FreelanceConversation extends Model { protected $fillable=['project_id','freelancer_service_id','status','last_message_at']; protected $casts=['last_message_at'=>'datetime']; public function project():BelongsTo{return $this->belongsTo(FreelanceProject::class,'project_id');} public function service():BelongsTo{return $this->belongsTo(FreelancerService::class,'freelancer_service_id');} public function participants():HasMany{return $this->hasMany(FreelanceConversationParticipant::class,'conversation_id');} public function messages():HasMany{return $this->hasMany(FreelanceMessage::class,'conversation_id');}}
