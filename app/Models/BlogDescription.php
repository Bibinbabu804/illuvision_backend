<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogDescription extends Model
{
    protected $fillable = [
        'blog_id',
        'title',
        'content',
        'image',
        'image_alt',
    ];

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }
}
