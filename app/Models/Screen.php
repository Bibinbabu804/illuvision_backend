<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Domain\Pricing\Enums\PricingUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Screen extends Model
{
    use SoftDeletes;

    const OWNER_TYPE_ILLUVISION = 'illuvision';
    const OWNER_TYPE_PROVIDER = 'screen_provider';

    public static function ownerTypes(): array
    {
        return [
            self::OWNER_TYPE_ILLUVISION => 'Illuvision',
            self::OWNER_TYPE_PROVIDER => 'Screen Provider',
        ];
    }

    protected $fillable = [
        'name',
        'screen_code',
        'asset_type',
        'pricing_unit',
        'fleet_size',
        'working_hours_pd',
        'travel_distance_km_pd',
        'display_specs',
        'asset_metadata',
        'screen_type',
        'screen_category_id',
        'country_id',
        'city_id',
        'location_name',
        'pricing_plan_id',
        'owner_type',
        'screen_provider_id',
        'illuvision_percentage',
        'screen_provider_percentage',
        'status',
        'booking_status',
        'sold_out_reason',
        'sold_out_from',
        'sold_out_until',
        'admin_notes',
        'orientation',
        'resolution',
        'operating_hours',
        'thumbnail',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'illuvision_percentage' => 'float',
        'screen_provider_percentage' => 'float',
        'fleet_size' => 'integer',
        'working_hours_pd' => 'integer',
        'travel_distance_km_pd' => 'integer',
        'asset_metadata' => 'array',
        'asset_type' => AssetType::class,
        'pricing_unit' => PricingUnit::class,
    ];

    protected $appends = [
        'is_bookable',
        'is_mobile',
    ];

    public function getIsBookableAttribute(): bool
    {
        return $this->status === 'active' && ($this->booking_status ?? 'available') === 'available';
    }

    public function getIsMobileAttribute(): bool
    {
        return $this->asset_type && $this->asset_type !== AssetType::FIXED;
    }

    protected static function booted()
    {
        static::deleting(function (Screen $screen) {
            if ($screen->thumbnail) {
                Storage::disk('public')->delete($screen->thumbnail);
            }
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function screenCategory(): BelongsTo
    {
        return $this->belongsTo(ScreenCategory::class, 'screen_category_id');
    }

    public function pricingPlan(): BelongsTo
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function screenProvider(): BelongsTo
    {
        return $this->belongsTo(ScreenProvider::class, 'screen_provider_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingScreen::class, 'screen_id');
    }

    public function coverageCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'mobile_asset_coverage_cities', 'mobile_asset_id', 'city_id')
            ->withTimestamps();
    }

    public function coverageStates(): BelongsToMany
    {
        return $this->belongsToMany(State::class, 'mobile_asset_coverage_states', 'mobile_asset_id', 'state_id')
            ->withTimestamps();
    }

    public function durationSplitOverrides(): HasMany
    {
        return $this->hasMany(ScreenDurationSplitOverride::class, 'screen_id');
    }

    public function isOwnedByIlluvision(): bool
    {
        return $this->owner_type === self::OWNER_TYPE_ILLUVISION;
    }

    public function isOwnedByProvider(): bool
    {
        return $this->owner_type === self::OWNER_TYPE_PROVIDER;
    }

    public function scopeFixed($query)
    {
        return $query->where('asset_type', AssetType::FIXED->value);
    }

    public function scopeMobile($query)
    {
        return $query->where('asset_type', '!=', AssetType::FIXED->value);
    }

    public function scopeForCity($query, $cityId)
    {
        return $this->scopeForCities($query, [$cityId]);
    }

    public function scopeForCities($query, array $cityIds)
    {
        $cityIds = array_filter((array) $cityIds);
        if (empty($cityIds)) {
            return $query;
        }

        return $query->where(function ($q) use ($cityIds) {
            // Fixed Display in any of the cities
            $q->where(function ($fq) use ($cityIds) {
                $fq->where(function ($typeQ) {
                    $typeQ->whereIn('asset_type', ['fixed', AssetType::FIXED->value])
                          ->orWhereNull('asset_type')
                          ->orWhere('asset_type', '');
                })
                ->whereIn('city_id', $cityIds);
            })
            // Mobile Fleet operating in any of the cities strictly via coverageCities
            ->orWhere(function ($mq) use ($cityIds) {
                $mq->whereNotIn('asset_type', ['fixed', AssetType::FIXED->value])
                   ->whereNotNull('asset_type')
                   ->where('asset_type', '!=', '')
                   ->whereHas('coverageCities', function ($cq) use ($cityIds) {
                       $cq->whereIn('city_id', $cityIds);
                   });
            });
        });
    }

    public function scopeForState($query, $stateId)
    {
        return $this->scopeForStates($query, [$stateId]);
    }

    public function scopeForStates($query, array $stateIds)
    {
        $stateIds = array_filter((array) $stateIds);
        if (empty($stateIds)) {
            return $query;
        }

        return $query->where(function ($q) use ($stateIds) {
            // Fixed Display strictly located in any of the states (via city.state_id)
            $q->where(function ($fq) use ($stateIds) {
                $fq->where(function ($typeQ) {
                    $typeQ->whereIn('asset_type', ['fixed', AssetType::FIXED->value])
                          ->orWhereNull('asset_type')
                          ->orWhere('asset_type', '');
                })
                ->whereHas('city', function ($cq) use ($stateIds) {
                    $cq->whereIn('state_id', $stateIds);
                });
            })
            // Mobile Fleet strictly serving state via coverageStates
            ->orWhere(function ($mq) use ($stateIds) {
                $mq->whereNotIn('asset_type', ['fixed', AssetType::FIXED->value])
                   ->whereNotNull('asset_type')
                   ->where('asset_type', '!=', '')
                   ->whereHas('coverageStates', function ($sq) use ($stateIds) {
                       $sq->whereIn('state_id', $stateIds);
                   });
            });
        });
    }

    public function scopeOwnedByIlluvision($query)
    {
        return $query->where('owner_type', self::OWNER_TYPE_ILLUVISION);
    }

    public function scopeOwnedByProvider($query)
    {
        return $query->where('owner_type', self::OWNER_TYPE_PROVIDER);
    }

    public function scopeOwnerType($query, ?string $ownerType)
    {
        if ($ownerType) {
            return $query->where('owner_type', $ownerType);
        }
        return $query;
    }

    public function scopeScreenProviderId($query, ?int $providerId)
    {
        if ($providerId) {
            return $query->where('screen_provider_id', $providerId);
        }
        return $query;
    }
}