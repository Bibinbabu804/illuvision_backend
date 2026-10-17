<?php

namespace Database\Seeders;

use App\Models\FreelanceConversation;
use App\Models\FreelanceConversationParticipant;
use App\Models\FreelanceMessage;
use App\Models\FreelanceNotification;
use App\Models\FreelancePayment;
use App\Models\FreelanceProject;
use App\Models\FreelanceReview;
use App\Models\FreelancerProfile;
use App\Models\FreelancerService;
use App\Models\FreelancerServicePackage;
use App\Models\FreelancerSkill;
use App\Models\FreelancerWallet;
use App\Models\FreelancerWalletTransaction;
use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FreelancingDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create or retrieve Freelancer User
        $freelancerUser = User::firstOrCreate(
            ['email' => 'freelancer@illuvision.com'],
            [
                'name' => 'Alex Vance',
                'password' => Hash::make('password123'),
                'role' => 'freelancer',
                'status' => 'active',
            ]
        );

        // 2. Freelancer Profile
        $profile = FreelancerProfile::firstOrCreate(
            ['user_id' => $freelancerUser->id],
            [
                'professional_title' => 'Senior 3D Motion & DOOH Visual Architect',
                'bio' => 'Over 8+ years crafting high-impact digital out-of-home campaigns, 3D anamorphic billboard animations, and high-performance brand identities. Trusted by global retail, luxury automotive, and tech startups.',
                'hourly_rate' => 85.00,
                'experience_years' => 8,
                'location' => 'San Francisco, CA & Remote',
                'languages' => ['English', 'German'],
                'availability' => 'full_time',
                'verification_status' => 'verified',
                'rating' => 4.98,
                'total_reviews' => 47,
                'total_projects' => 64,
                'total_earnings' => 18650.00,
            ]
        );

        // 3. Skills
        $skills = [
            '3D Motion Graphics',
            'Anamorphic Billboard Design',
            'Cinema 4D',
            'After Effects',
            'Unreal Engine 5',
            'DOOH Ad Optimization',
            'Brand Identity',
            'Next.js Interactive Creative',
        ];
        foreach ($skills as $skill) {
            FreelancerSkill::firstOrCreate([
                'freelancer_profile_id' => $profile->id,
                'slug' => Str::slug($skill),
            ], [
                'name' => $skill,
            ]);
        }

        // 4. Wallet
        $wallet = FreelancerWallet::firstOrCreate(
            ['freelancer_profile_id' => $profile->id],
            [
                'available_balance' => 3450.00,
                'pending_balance' => 1200.00,
                'lifetime_earnings' => 18650.00,
            ]
        );

        FreelancerWalletTransaction::firstOrCreate(
            ['wallet_id' => $wallet->id, 'reference' => 'TXN-DEMO-INIT-001'],
            [
                'type' => 'credit',
                'amount' => 1250.00,
                'balance_after' => 3450.00,
                'description' => 'Escrow milestone release for Metro Square 3D Billboard',
                'created_at' => now()->subDays(2),
            ]
        );

        // 5. Create or retrieve Client User
        $clientUser = User::firstOrCreate(
            ['email' => 'client@illuvision.com'],
            [
                'name' => 'Sarah Jenkins',
                'password' => Hash::make('password123'),
                'role' => 'client',
                'status' => 'active',
            ]
        );

        // 6. Services & Packages
        $service1 = FreelancerService::firstOrCreate(
            ['slug' => '3d-anamorphic-billboard-animation'],
            [
                'freelancer_profile_id' => $profile->id,
                'title' => 'Hyper-Realistic 3D Anamorphic Billboard & Corner DOOH Animation',
                'category' => '3D Motion Design',
                'subcategory' => 'Billboard Animation',
                'description' => 'Create mind-bending optical illusion 3D billboard creative tailored for LED corner screens, Times Square format, and naked-eye 3D architectural displays.',
                'status' => 'published',
                'featured_image' => 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=1200&q=80',
                'rating' => 5.0,
                'review_count' => 32,
                'orders_completed' => 45,
            ]
        );

        $packagesData1 = [
            [
                'name' => 'Basic',
                'price' => 450.00,
                'delivery_days' => 4,
                'revisions' => 2,
                'features' => ['5-second 4K loop', 'Single perspective calibration', 'Audio design', 'Hardware export ready'],
            ],
            [
                'name' => 'Standard',
                'price' => 950.00,
                'delivery_days' => 7,
                'revisions' => 4,
                'features' => ['15-second commercial', 'Dual viewing angle correction', 'Raytraced lighting', 'Social + DOOH formats'],
            ],
            [
                'name' => 'Premium',
                'price' => 1950.00,
                'delivery_days' => 14,
                'revisions' => 6,
                'features' => ['30-second multi-screen suite', 'Day/night environmental tuning', 'On-site LED simulation preview', 'Full source files'],
            ],
        ];

        foreach ($packagesData1 as $pkg) {
            FreelancerServicePackage::firstOrCreate(
                ['freelancer_service_id' => $service1->id, 'name' => $pkg['name']],
                $pkg
            );
        }

        $service2 = FreelancerService::firstOrCreate(
            ['slug' => 'dooh-brand-identity-campaign-system'],
            [
                'freelancer_profile_id' => $profile->id,
                'title' => 'Digital Out-Of-Home Brand Identity & Campaign Design System',
                'category' => 'Branding & Graphics',
                'subcategory' => 'Campaign Systems',
                'description' => 'Comprehensive visual system designed specifically to grab eyes in high-velocity outdoor environments. Includes typography, motion guidelines, and digital layout matrices.',
                'status' => 'published',
                'featured_image' => 'https://images.unsplash.com/photo-1542744094-3a3172720a6a?auto=format&fit=crop&w=1200&q=80',
                'rating' => 4.9,
                'review_count' => 15,
                'orders_completed' => 19,
            ]
        );

        $packagesData2 = [
            [
                'name' => 'Basic',
                'price' => 300.00,
                'delivery_days' => 3,
                'revisions' => 3,
                'features' => ['Outdoor contrast typography', 'Day & Night palette audit', '3 Screen aspect ratios'],
            ],
            [
                'name' => 'Standard',
                'price' => 750.00,
                'delivery_days' => 6,
                'revisions' => 5,
                'features' => ['Complete outdoor identity guide', '10 Screen template layouts', 'Animated logo stingers'],
            ],
            [
                'name' => 'Premium',
                'price' => 1400.00,
                'delivery_days' => 12,
                'revisions' => 8,
                'features' => ['Global omnichannel design tokens', 'Multi-city localized variations', 'Unreal/AfterEffects templates'],
            ],
        ];

        foreach ($packagesData2 as $pkg) {
            FreelancerServicePackage::firstOrCreate(
                ['freelancer_service_id' => $service2->id, 'name' => $pkg['name']],
                $pkg
            );
        }

        // 7. Portfolio Items
        PortfolioItem::firstOrCreate(
            ['freelancer_profile_id' => $profile->id, 'title' => 'Cyberpunk Holographic Sneaker Launch — Tokyo Shinjuku Screen'],
            [
                'description' => 'A breakthrough 3D illusion project that made sneakers appear to hover 5 meters outside the physical LED screen wall.',
                'category' => '3D Motion Design',
                'project_url' => 'https://illuvision.com/showcase/tokyo-3d-sneaker',
                'skills' => ['Cinema 4D', 'Redshift', 'Anamorphic 3D'],
                'completed_at' => now()->subMonths(1),
                'visibility' => 'public',
                'status' => 'published',
                'views' => 1420,
                'is_featured' => true,
            ]
        );

        PortfolioItem::firstOrCreate(
            ['freelancer_profile_id' => $profile->id, 'title' => 'Lumina Electric Hypercar — Geneva Digital Horizon'],
            [
                'description' => 'Photorealistic carbon fiber and aerodynamic laser light simulation across a 120-meter airport mega-display.',
                'category' => '3D Motion Design',
                'project_url' => 'https://illuvision.com/showcase/lumina-hypercar',
                'skills' => ['Unreal Engine 5', 'Raytracing', 'DOOH Simulation'],
                'completed_at' => now()->subMonths(3),
                'visibility' => 'public',
                'status' => 'published',
                'views' => 980,
                'is_featured' => true,
            ]
        );

        // 8. Demo Projects
        $pkgStandard = FreelancerServicePackage::where('freelancer_service_id', $service1->id)->where('name', 'Standard')->first();

        // Project A: In Progress with Escrow Payment
        $projectActive = FreelanceProject::firstOrCreate(
            ['reference' => 'PRJ-DEMO-2026-001'],
            [
                'client_id' => $clientUser->id,
                'freelancer_profile_id' => $profile->id,
                'freelancer_service_id' => $service1->id,
                'freelancer_service_package_id' => $pkgStandard?->id,
                'title' => 'Urban Glow Fall 3D Campaign for Downtown Mall',
                'description' => 'Produce a 15-second dynamic 3D leaf dispersion animation breaking through a glass window frame.',
                'status' => 'in_progress',
                'gross_amount' => 950.00,
                'platform_fee' => 47.50,
                'freelancer_amount' => 902.50,
                'delivery_days' => 7,
                'revision_limit' => 4,
                'revision_count' => 0,
                'due_at' => now()->addDays(5),
            ]
        );

        FreelancePayment::firstOrCreate(
            ['project_id' => $projectActive->id],
            [
                'client_id' => $clientUser->id,
                'gateway' => 'razorpay',
                'gateway_order_id' => 'order_demo_escrow_001',
                'amount' => 950.00,
                'currency' => 'INR',
                'status' => 'held_in_escrow',
                'held_at' => now()->subHours(8),
            ]
        );

        // Project B: Completed with 5-Star Review
        $projectCompleted = FreelanceProject::firstOrCreate(
            ['reference' => 'PRJ-DEMO-2026-002'],
            [
                'client_id' => $clientUser->id,
                'freelancer_profile_id' => $profile->id,
                'freelancer_service_id' => $service1->id,
                'freelancer_service_package_id' => $pkgStandard?->id,
                'title' => 'Neon Cyber-Tiger DOOH 4K Teaser',
                'description' => 'A neon cyber-tiger stalking across three synchronized LED towers.',
                'status' => 'completed',
                'gross_amount' => 1250.00,
                'platform_fee' => 62.50,
                'freelancer_amount' => 1187.50,
                'delivery_days' => 5,
                'revision_limit' => 3,
                'revision_count' => 1,
                'completed_at' => now()->subDays(2),
            ]
        );

        FreelanceReview::firstOrCreate(
            ['project_id' => $projectCompleted->id],
            [
                'client_id' => $clientUser->id,
                'freelancer_profile_id' => $profile->id,
                'rating' => 5,
                'review' => 'Alex delivered the most astonishing 3D work we have ever commissioned. Foot traffic at the mall increased by 32% purely because people were recording the display on their phones. Unmatched excellence!',
                'status' => 'published',
            ]
        );

        // 9. Conversations & Messages
        $conversation = FreelanceConversation::firstOrCreate([
            'project_id' => $projectActive->id,
        ], [
            'status' => 'open',
            'last_message_at' => now()->subHours(2),
        ]);

        FreelanceConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $freelancerUser->id,
        ]);

        FreelanceConversationParticipant::firstOrCreate([
            'conversation_id' => $conversation->id,
            'user_id' => $clientUser->id,
        ]);

        FreelanceMessage::firstOrCreate(
            ['conversation_id' => $conversation->id, 'sender_id' => $clientUser->id],
            [
                'body' => 'Hi Alex! Excited to work together on the Downtown Mall screen. Have you had a chance to inspect the LED specs?',
                'created_at' => now()->subHours(6),
            ]
        );

        FreelanceMessage::firstOrCreate(
            ['conversation_id' => $conversation->id, 'sender_id' => $freelancerUser->id],
            [
                'body' => 'Hey Sarah! Yes, the 3840x2160 native resolution with the 90-degree corner bezel is ideal. I have started the volumetric lighting pass today!',
                'created_at' => now()->subHours(2),
            ]
        );

        // 10. Notifications
        FreelanceNotification::firstOrCreate(
            ['user_id' => $freelancerUser->id, 'title' => 'Escrow Funded ($950.00)'],
            [
                'type' => 'payment_funded',
                'body' => 'Client Sarah Jenkins deposited $950.00 into escrow for Urban Glow Fall 3D Campaign.',
                'data' => ['project_id' => $projectActive->id, 'amount' => 950.00],
                'read_at' => null,
                'created_at' => now()->subHours(5),
            ]
        );

        FreelanceNotification::firstOrCreate(
            ['user_id' => $freelancerUser->id, 'title' => '5-Star Review Received'],
            [
                'type' => 'review_received',
                'body' => 'Sarah Jenkins left a 5-star review on Neon Cyber-Tiger DOOH 4K Teaser.',
                'data' => ['project_id' => $projectCompleted->id, 'rating' => 5],
                'read_at' => now()->subDays(1),
                'created_at' => now()->subDays(2),
            ]
        );
    }
}
