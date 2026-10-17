<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_future_published_and_draft_blogs()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin-token')->plainTextToken;

        // Create a blog scheduled in the future
        $futureBlog = Blog::create([
            'name' => 'Future Post',
            'slug' => 'future-post',
            'status' => 'published',
            'published_at' => now()->addDays(5),
        ]);

        // Create a draft blog
        $draftBlog = Blog::create([
            'name' => 'Draft Post',
            'slug' => 'draft-post',
            'status' => 'draft',
            'published_at' => null,
        ]);

        // Unauthenticated public index should NOT see future or draft blogs
        $publicRes = $this->getJson('/api/blogs');
        $publicRes->assertStatus(200);
        $publicTitles = collect($publicRes->json('data'))->pluck('name');
        $this->assertNotContains('Future Post', $publicTitles);
        $this->assertNotContains('Draft Post', $publicTitles);

        // Admin request with Bearer token SHOULD see all blogs
        $adminRes = $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/blogs');
        $adminRes->assertStatus(200);
        $adminTitles = collect($adminRes->json('data'))->pluck('name');
        $this->assertContains('Future Post', $adminTitles);
        $this->assertContains('Draft Post', $adminTitles);
    }
}
