<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('roles')->insert([
            ['name' => 'Super Admin', 'slug' => 'super-admin', 'description' => 'Full system access. Can manage all settings, users, and permissions.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Operator', 'slug' => 'operator', 'description' => 'Day-to-day operations. Manage bookings, screens, and content.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Content Reviewer', 'slug' => 'content-reviewer', 'description' => 'Review and approve media content. Limited operational access.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Viewer', 'slug' => 'viewer', 'description' => 'Read-only access to dashboards and reports.', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
