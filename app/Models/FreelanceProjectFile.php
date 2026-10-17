<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelanceProjectFile extends Model
{
    protected $fillable = ['project_id', 'uploaded_by', 'purpose', 'original_name', 'path', 'mime_type', 'size'];

    public function project(): BelongsTo { return $this->belongsTo(FreelanceProject::class, 'project_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
