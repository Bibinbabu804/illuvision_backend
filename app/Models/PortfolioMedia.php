<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioMedia extends Model
{
    protected $fillable = [
        'portfolio_item_id', 'uploaded_by', 'media_type', 'original_name', 'file_name', 'path',
        'thumbnail_path', 'mime_type', 'size', 'sort_order', 'is_cover', 'moderation_status',
    ];

    protected $casts = [
        'is_cover' => 'boolean',
        'sort_order' => 'integer',
        'size' => 'integer',
    ];

    public function portfolioItem(): BelongsTo { return $this->belongsTo(PortfolioItem::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
