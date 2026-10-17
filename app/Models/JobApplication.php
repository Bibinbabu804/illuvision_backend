<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobApplication extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'resume',
        'job_id',
    ];

    public function job()
    {
        return $this->belongsTo(JobPost::class);
    }
}
