<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobPost extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'description',
        'location',
        'type',
        'company',
        'category_id',
        'due_date',
    ];

    protected $appends = [
        'posted'
    ];

    public function getPostedAttribute(){
        return $this->created_at->diffForHumans();
    }

    public function getDueDateAttribute($value){
        return $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
    }

    public function applications()
    {
        return $this->hasMany(JobApplication::class);
    }
}
