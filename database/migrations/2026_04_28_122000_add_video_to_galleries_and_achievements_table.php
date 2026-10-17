<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('image')->nullable()->change();
            $table->string('video')->nullable()->after('image');
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->string('video')->nullable()->after('image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('image')->nullable(false)->change();
            $table->dropColumn('video');
        });

        Schema::table('achievements', function (Blueprint $table) {
            $table->dropColumn('video');
        });
    }
};
