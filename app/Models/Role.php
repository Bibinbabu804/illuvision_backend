<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function grantedPermissions(): array
    {
        return $this->permissions->where('granted', true)->pluck('permission')->toArray();
    }
}
