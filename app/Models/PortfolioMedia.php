<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioMedia extends Model
{
    protected $fillable = [
        'uploaded_by', 'media_type', 'original_name', 'file_name', 'path',
        'mime_type', 'size', 'sort_order',
    ];

    public function portfolioItem(): BelongsTo { return $this->belongsTo(PortfolioItem::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
