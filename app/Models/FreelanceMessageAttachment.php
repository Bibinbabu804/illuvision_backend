<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FreelanceMessageAttachment extends Model { protected $fillable=['message_id','uploaded_by','original_name','path','mime_type','size']; public function message():BelongsTo{return $this->belongsTo(FreelanceMessage::class,'message_id');} public function uploader():BelongsTo{return $this->belongsTo(User::class,'uploaded_by');}}
