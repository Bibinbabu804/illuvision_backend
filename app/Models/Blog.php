<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blog extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'short_description',
        'resource',
        'image_alt',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'canonical_tag',
        'views',
        'description',
        'category_id',
        'banner_image',
        'due_date',
        'author_id',
        'status',
        'published_at',
        'og_image',
        'schema_data',
        'reading_time',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'due_date' => 'date',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_tag_relations', 'blog_id', 'blog_tag_id');
    }

    public function descriptionBlocks(): HasMany
    {
        return $this->hasMany(BlogDescription::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->published()->orderBy('published_at', 'desc')->limit(3);
    }
}
