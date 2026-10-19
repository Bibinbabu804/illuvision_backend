<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FreelanceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Graphics & Design',
                'type' => 'both',
                'description' => 'Visual design, branding, illustration, and creative assets.',
                'icon' => 'Palette',
                'children' => [
                    'Logo & Brand Identity',
                    'Web & App Design',
                    'Art & Illustration',
                    'Print Design',
                    'Social Media Design',
                ],
            ],
            [
                'name' => 'Programming & Tech',
                'type' => 'both',
                'description' => 'Software engineering, web, mobile, and technical solutions.',
                'icon' => 'Code',
                'children' => [
                    'Web Development',
                    'Mobile Apps',
                    'AI & Machine Learning',
                    'DevOps & Cloud',
                    'Database & Backend',
                ],
            ],
            [
                'name' => 'Digital Marketing',
                'type' => 'both',
                'description' => 'Growth, search engine optimization, and advertising campaigns.',
                'icon' => 'Megaphone',
                'children' => [
                    'Social Media Marketing',
                    'SEO & Search Engine Optimization',
                    'Performance Marketing',
                    'Content Marketing',
                ],
            ],
            [
                'name' => 'Video & Animation',
                'type' => 'both',
                'description' => 'Motion design, video editing, 3D, and visual effects.',
                'icon' => 'Video',
                'children' => [
                    'Video Editing',
                    '3D Animation',
                    'Motion Graphics',
                    'Intro & Outro Animation',
                ],
            ],
            [
                'name' => 'Writing & Translation',
                'type' => 'both',
                'description' => 'Articles, copywriting, translation, and technical documentation.',
                'icon' => 'FileText',
                'children' => [
                    'Articles & Blog Posts',
                    'Copywriting & Ad Copy',
                    'Translation & Localization',
                    'Technical Writing',
                ],
            ],
            [
                'name' => 'Music & Audio',
                'type' => 'both',
                'description' => 'Voiceover, sound engineering, mixing, and music composition.',
                'icon' => 'Headphones',
                'children' => [
                    'Voice Over',
                    'Sound Design & FX',
                    'Mixing & Mastering',
                    'Audiobook Production',
                ],
            ],
        ];

        foreach ($categories as $index => $cat) {
            $parentId = DB::table('freelance_categories')->insertGetId([
                'name' => $cat['name'],
                'slug' => Str::slug($cat['name']),
                'type' => $cat['type'],
                'description' => $cat['description'],
                'icon' => $cat['icon'],
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($cat['children'] as $childIndex => $childName) {
                DB::table('freelance_categories')->insert([
                    'parent_id' => $parentId,
                    'name' => $childName,
                    'slug' => Str::slug($childName),
                    'type' => 'both',
                    'description' => $childName . ' services and portfolio works.',
                    'icon' => null,
                    'is_active' => true,
                    'sort_order' => $childIndex,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
