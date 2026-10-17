<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;


class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // CountrySeeder::class,
            LocationHierarchySeeder::class,
            CurrencyRateSeeder::class,
            // AdvertisementCategorySeeder::class,
            // PricingPlanSeeder::class,
            // KeralaScreensSeeder::class,
            // ScreenProviderRoleSeeder::class,
            // RolesAndPermissionsSeeder::class,
            // AssetSeeder::class,
        ]);

        // User::factory(10)->create();
        // User::factory()->create([
        //     'name' => 'Admin',
        //     'email' => 'admin@illuvision.com',
        //     'role' => 'admin',
        //     'password' => 'illuvision@admin',
        // ]);
    }
}
