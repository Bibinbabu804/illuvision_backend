<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete()->after('id');
            $table->string('status')->default('draft')->after('canonical_tag');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->string('og_image')->nullable()->after('banner_image');
            $table->longText('schema_data')->nullable()->after('og_image');
            $table->unsignedSmallInteger('reading_time')->default(1)->after('views');
        });
    }

    public function down(): void
    {
        Schema::table('blogs', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
            $table->dropColumn(['author_id', 'status', 'published_at', 'og_image', 'schema_data', 'reading_time']);
        });
    }
};
