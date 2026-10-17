<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenProviderActivity extends Model
{
    use HasFactory;

    protected $fillable = [
        'screen_provider_id',
        'type',
        'description',
        'performed_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function screenProvider(): BelongsTo
    {
        return $this->belongsTo(ScreenProvider::class);
    }

    public function performedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
