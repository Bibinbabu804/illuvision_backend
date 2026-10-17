<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add screen_category_id to screens table
        Schema::table('screens', function (Blueprint $table) {
            if (!Schema::hasColumn('screens', 'screen_category_id')) {
                $table->foreignId('screen_category_id')->nullable()->after('asset_type')->constrained('screen_categories')->nullOnDelete();
            }
        });

        // 2. Add category snapshot columns to booking_screens table
        Schema::table('booking_screens', function (Blueprint $table) {
            if (!Schema::hasColumn('booking_screens', 'screen_category_id')) {
                $table->foreignId('screen_category_id')->nullable()->after('screen_id');
            }
            if (!Schema::hasColumn('booking_screens', 'screen_category_name')) {
                $table->string('screen_category_name')->nullable()->after('screen_category_id');
            }
        });

        // 3. Add category snapshot columns to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'screen_category_id')) {
                $table->foreignId('screen_category_id')->nullable()->after('screen_id');
            }
            if (!Schema::hasColumn('bookings', 'screen_category_name')) {
                $table->string('screen_category_name')->nullable()->after('screen_category_id');
            }
        });

        // 4. Seed initial master categories
        $defaultCategories = [
            ['name' => 'Standard Display', 'description' => 'General purpose fixed or mobile display screen.', 'sort_order' => 1],
            ['name' => 'Restaurant Display', 'description' => 'Digital menu boards, dining area screens, and food truck displays.', 'sort_order' => 2],
            ['name' => 'Bus Terminal', 'description' => 'Bus station arrival/departure screens and waiting lounge displays.', 'sort_order' => 3],
            ['name' => 'Theatre Screen', 'description' => 'Cinema lobby screens, auditorium displays, and mobile cinema units.', 'sort_order' => 4],
            ['name' => 'Bus Screen', 'description' => 'On-bus/in-bus digital displays and mobile transit screens.', 'sort_order' => 5],
            ['name' => 'Bus Stop / Shelter Display', 'description' => 'Fixed outdoor displays at bus stops and public transit shelters.', 'sort_order' => 6],
            ['name' => 'LED Billboard', 'description' => 'Large format highway and roadside outdoor digital LED screens.', 'sort_order' => 7],
            ['name' => 'Mall Display', 'description' => 'Shopping mall concourse, atrium, and retail arcade screens.', 'sort_order' => 8],
            ['name' => 'Airport Screen', 'description' => 'Airport terminal, gate, and baggage claim digital screens.', 'sort_order' => 9],
            ['name' => 'Digital Totem', 'description' => 'Free-standing indoor and outdoor digital totem kiosks.', 'sort_order' => 10],
            ['name' => 'LED Van', 'description' => 'Mobile advertising LED vans with high-brightness displays.', 'sort_order' => 11],
            ['name' => 'LED Truck', 'description' => 'Heavy-duty mobile LED advertising trucks.', 'sort_order' => 12],
            ['name' => 'Backpack Team', 'description' => 'Walking digital backpack promotional advertising teams.', 'sort_order' => 13],
            ['name' => 'Event Display', 'description' => 'Temporary pop-up displays for events and exhibitions.', 'sort_order' => 14],
            ['name' => 'Other', 'description' => 'Custom and unclassified screen displays.', 'sort_order' => 15],
        ];

        $now = now();
        $categoryMap = [];

        foreach ($defaultCategories as $cat) {
            $slug = Str::slug($cat['name']);
            $existing = DB::table('screen_categories')->where('slug', $slug)->first();
            if (!$existing) {
                $id = DB::table('screen_categories')->insertGetId([
                    'name' => $cat['name'],
                    'slug' => $slug,
                    'description' => $cat['description'],
                    'status' => 'active',
                    'sort_order' => $cat['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryMap[strtolower($cat['name'])] = $id;
            } else {
                $categoryMap[strtolower($cat['name'])] = $existing->id;
            }
        }

        $standardCategoryId = $categoryMap['standard display'] ?? DB::table('screen_categories')->min('id');

        // 5. Migrate existing screens to assign appropriate screen_category_id
        $screens = DB::table('screens')->get();
        foreach ($screens as $screen) {
            if (!$screen->screen_category_id) {
                $targetCatId = $standardCategoryId;
                if (!empty($screen->screen_type)) {
                    $lowerType = strtolower(trim($screen->screen_type));
                    foreach ($categoryMap as $nameKey => $catId) {
                        if ($lowerType === $nameKey || str_contains($lowerType, $nameKey) || str_contains($nameKey, $lowerType)) {
                            $targetCatId = $catId;
                            break;
                        }
                    }
                }
                DB::table('screens')->where('id', $screen->id)->update(['screen_category_id' => $targetCatId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            if (Schema::hasColumn('screens', 'screen_category_id')) {
                $table->dropForeign(['screen_category_id']);
                $table->dropColumn('screen_category_id');
            }
        });

        Schema::table('booking_screens', function (Blueprint $table) {
            if (Schema::hasColumn('booking_screens', 'screen_category_id')) {
                $table->dropColumn(['screen_category_id', 'screen_category_name']);
            }
        });

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'screen_category_id')) {
                $table->dropColumn(['screen_category_id', 'screen_category_name']);
            }
        });
    }
};
