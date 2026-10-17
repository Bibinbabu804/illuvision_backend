<?php

namespace App\Models;

use App\Support\Authorization\RolePermissionChecker;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar',
        'status',
        'last_login_at',
        'google_id',
        'created_by_partner_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
    ];

    // ─── Relationships ───────────────────────────────────

    public function createdByPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'created_by_partner_id');
    }

    public function partnerClients(): HasMany
    {
        return $this->hasMany(User::class, 'created_by_partner_id');
    }

    public function screenProvider(): HasOne
    {
        return $this->hasOne(ScreenProvider::class, 'user_id');
    }

    public function rewardAccount(): HasOne
    {
        return $this->hasOne(RewardAccount::class);
    }

    public function freelancerProfile(): HasOne
    {
        return $this->hasOne(FreelancerProfile::class);
    }

    public function freelanceNotifications(): HasMany
    {
        return $this->hasMany(FreelanceNotification::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function approvedBookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'approved_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ─── Helpers ─────────────────────────────────────────

    public function hasPermission(string $permission): bool
    {
        return app(RolePermissionChecker::class)->userHasPermission($this, $permission);
    }

    public function isSuperAdmin(): bool
    {
        return in_array($this->role, ['super-admin', 'superadmin']);
    }

    public function scopeByRole($query, string $roleSlug)
    {
        return $query->where('role', $roleSlug);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAdminUsers($query)
    {
        return $query->whereIn('role', ['super-admin', 'superadmin', 'admin', 'manager', 'staff']);
    }
}
