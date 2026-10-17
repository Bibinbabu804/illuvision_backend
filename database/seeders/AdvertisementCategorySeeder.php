<?php

namespace Database\Seeders;

use App\Models\AdvertisementCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdvertisementCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Restaurant',
                'description' => 'Food, beverages, dining, cafes, and quick service restaurants.',
                'icon' => 'Utensils',
                'color' => '#EF4444',
                'sort_order' => 1,
            ],
            [
                'name' => 'Retail',
                'description' => 'Shops, supermarkets, clothing stores, and consumer products.',
                'icon' => 'ShoppingBag',
                'color' => '#F59E0B',
                'sort_order' => 2,
            ],
            [
                'name' => 'Healthcare',
                'description' => 'Hospitals, clinics, pharmacies, and wellness centers.',
                'icon' => 'HeartPulse',
                'color' => '#10B981',
                'sort_order' => 3,
            ],
            [
                'name' => 'Education',
                'description' => 'Schools, universities, coaching classes, and e-learning platforms.',
                'icon' => 'GraduationCap',
                'color' => '#3B82F6',
                'sort_order' => 4,
            ],
            [
                'name' => 'Real Estate',
                'description' => 'Properties, apartments, home rentals, and construction developers.',
                'icon' => 'Home',
                'color' => '#8B5CF6',
                'sort_order' => 5,
            ],
            [
                'name' => 'Fashion',
                'description' => 'Clothing apparel, footwear, jewelry, and luxury accessories.',
                'icon' => 'Shirt',
                'color' => '#EC4899',
                'sort_order' => 6,
            ],
            [
                'name' => 'Technology',
                'description' => 'Software services, gadgets, internet services, and startups.',
                'icon' => 'Cpu',
                'color' => '#06B6D4',
                'sort_order' => 7,
            ],
            [
                'name' => 'Entertainment',
                'description' => 'Movies, music concerts, streaming, theaters, and amusement parks.',
                'icon' => 'Film',
                'color' => '#D946EF',
                'sort_order' => 8,
            ],
            [
                'name' => 'Automobile',
                'description' => 'Car dealerships, showrooms, auto repair, and vehicle accessories.',
                'icon' => 'Car',
                'color' => '#6366F1',
                'sort_order' => 9,
            ],
            [
                'name' => 'Travel',
                'description' => 'Tourism boards, hotels, travel booking, and airlines.',
                'icon' => 'Compass',
                'color' => '#14B8A6',
                'sort_order' => 10,
            ],
            [
                'name' => 'Finance',
                'description' => 'Banking, insurance, investments, and loan services.',
                'icon' => 'DollarSign',
                'color' => '#84CC16',
                'sort_order' => 11,
            ],
            [
                'name' => 'Events',
                'description' => 'Conferences, weddings, exhibitions, and corporate events.',
                'icon' => 'Calendar',
                'color' => '#F43F5E',
                'sort_order' => 12,
            ],
            [
                'name' => 'Government',
                'description' => 'Public services, announcements, campaigns, and municipalities.',
                'icon' => 'Building',
                'color' => '#64748B',
                'sort_order' => 13,
            ],
            [
                'name' => 'Other',
                'description' => 'Any other campaign category that does not fit predefined sectors.',
                'icon' => 'MoreHorizontal',
                'color' => '#94A3B8',
                'sort_order' => 14,
            ]
        ];

        foreach ($categories as $cat) {
            AdvertisementCategory::firstOrCreate(
                ['slug' => Str::slug($cat['name'])],
                [
                    'name' => $cat['name'],
                    'description' => $cat['description'],
                    'icon' => $cat['icon'],
                    'color' => $cat['color'],
                    'status' => 'active',
                    'sort_order' => $cat['sort_order'],
                ]
            );
        }
    }
}
