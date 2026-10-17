<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('granted')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'permission']);
        });

        $permissions = [
            'manage_screens', 'manage_pricing', 'approve_bookings',
            'upload_media', 'view_reports', 'manage_users',
        ];

        $roles = DB::table('roles')->get();
        $now = now();

        $rolePermissions = [
            'super-admin' => $permissions,
            'operator' => ['manage_screens', 'approve_bookings', 'upload_media', 'view_reports'],
            'content-reviewer' => ['upload_media', 'view_reports'],
            'viewer' => ['view_reports'],
        ];

        foreach ($rolePermissions as $slug => $perms) {
            $roleId = $roles->firstWhere('slug', $slug)->id;
            foreach ($perms as $perm) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission' => $perm,
                    'granted' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
