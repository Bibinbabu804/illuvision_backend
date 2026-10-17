<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function jobs()
    {
        return $this->hasMany(Job::class);
    }
}
