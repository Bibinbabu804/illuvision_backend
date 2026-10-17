<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'target_url',
        'description',
        'scan_count',
    ];

    protected $casts = [
        'scan_count' => 'integer',
    ];

    public function scans()
    {
        return $this->hasMany(QrCodeScan::class);
    }
}
