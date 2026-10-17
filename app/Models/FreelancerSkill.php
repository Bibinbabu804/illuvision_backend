<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerSkill extends Model
{
    protected $fillable = ['name', 'slug'];

    public function freelancerProfile(): BelongsTo { return $this->belongsTo(FreelancerProfile::class); }
}
