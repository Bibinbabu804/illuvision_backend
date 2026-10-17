<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screen_providers', function (Blueprint $table) {
            $table->decimal('illuvision_percentage', 5, 2)->default(10.00)->after('notes');
            $table->decimal('screen_provider_percentage', 5, 2)->default(90.00)->after('illuvision_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('screen_providers', function (Blueprint $table) {
            $table->dropColumn(['illuvision_percentage', 'screen_provider_percentage']);
        });
    }
};
