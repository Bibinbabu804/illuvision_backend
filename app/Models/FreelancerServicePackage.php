<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerServicePackage extends Model
{
    protected $fillable = ['name', 'title', 'description', 'price', 'delivery_days', 'revisions', 'features'];

    protected $casts = ['price' => 'decimal:2', 'features' => 'array'];

    public function service(): BelongsTo { return $this->belongsTo(FreelancerService::class, 'freelancer_service_id'); }
}
