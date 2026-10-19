<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerUnavailability extends Model
{
    protected $table = 'freelancer_unavailabilities';

    protected $fillable = [
        'freelancer_profile_id',
        'from_date',
        'to_date',
        'reason',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
    ];

    public function freelancerProfile(): BelongsTo
    {
        return $this->belongsTo(FreelancerProfile::class, 'freelancer_profile_id');
    }
}
