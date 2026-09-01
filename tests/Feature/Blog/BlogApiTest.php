<?php

namespace Tests\Feature\Blog;

use App\Enum\Blog\BlogPostStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlogApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
    }

    /*
    |--------------------------------------------------------------------------
    | The reader's side
    |--------------------------------------------------------------------------
    */

    public function test_only_published_and_already_dated_articles_are_listed(): void
    {
        $this->actingAsReader();

        $live = BlogPost::factory()->published()->create();
        BlogPost::factory()->create();
        BlogPost::factory()->scheduled()->create();
        BlogPost::factory()->create(['status' => BlogPostStatusEnum::Archived->value, 'published_at' => now()->subDay()]);

        $response = $this->getJson('/api/blog/posts')->assertOk();

        // A draft, a piece dated for next week and an archived one are all invisible.
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame($live->getKey(), $response->json('data.data.0.id'));
    }

    public function test_the_listing_ships_the_excerpt_but_not_the_article(): void
    {
        $this->actingAsReader();
        BlogPost::factory()->published()->create(['excerpt' => 'ملخّص قصير']);

        $row = $this->getJson('/api/blog/posts')->assertOk()->json('data.data.0');

        $this->assertSame('ملخّص قصير', $row['excerpt']);
        // Sixty cards must not mean sixty essays on the wire.
        $this->assertArrayNotHasKey('content', $row);
    }

    public function test_articles_can_be_filtered_by_category(): void
    {
        $this->actingAsReader();
        $guides = BlogCategory::factory()->create(['slug' => 'guides']);
        BlogPost::factory()->published()->create(['category_id' => $guides->getKey()]);
        BlogPost::factory()->published()->create();

        $this->getJson('/api/blog/posts?category=guides')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.category.slug', 'guides');
    }

    public function test_an_article_is_read_by_its_slug_and_counted(): void
    {
        $this->actingAsReader();
        $post = BlogPost::factory()->published()->create(['slug' => 'كيف-تدير-مغسلتك', 'content' => 'النص الكامل']);

        $this->getJson('/api/blog/posts/كيف-تدير-مغسلتك')
            ->assertOk()
            ->assertJsonPath('data.content', 'النص الكامل')
            ->assertJsonPath('data.slug', 'كيف-تدير-مغسلتك');

        $this->assertSame(1, $post->fresh()->view_count);
    }

    public function test_reading_an_article_is_not_an_edit(): void
    {
        $this->actingAsReader();
        $post = BlogPost::factory()->published()->create();
        $touchedAt = $post->updated_at;

        $this->getJson("/api/blog/posts/{$post->slug}")->assertOk();

        // The counter must not make an article look freshly revised.
        $this->assertEquals($touchedAt, $post->fresh()->updated_at);
    }

    public function test_an_unpublished_article_does_not_exist_to_a_reader(): void
    {
        $this->actingAsReader();
        $draft = BlogPost::factory()->create();
        $scheduled = BlogPost::factory()->scheduled()->create();

        $this->getJson("/api/blog/posts/{$draft->slug}")->assertStatus(404);
        $this->getJson("/api/blog/posts/{$scheduled->slug}")->assertStatus(404);
    }

    public function test_the_category_filter_lists_only_active_categories(): void
    {
        $this->actingAsReader();
        BlogCategory::factory()->create(['name' => 'أدلة']);
        BlogCategory::factory()->create(['name' => 'مطوي', 'is_active' => false]);

        $names = array_column($this->getJson('/api/blog/categories')->assertOk()->json('data'), 'name');

        $this->assertSame(['أدلة'], $names);
    }

    /*
    |--------------------------------------------------------------------------
    | The operator's desk
    |--------------------------------------------------------------------------
    */

    public function test_an_article_starts_as_a_draft_with_no_publish_date(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/blog/posts', ['title' => 'دليل التسعير', 'content' => 'النص'])
            ->assertCreated()
            ->assertJsonPath('data.status', BlogPostStatusEnum::Draft->value)
            ->assertJsonPath('data.published_at', null);
    }

    public function test_the_slug_is_derived_from_the_title_and_keeps_arabic(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/blog/posts', ['title' => 'كيف تدير مغسلتك', 'content' => 'النص'])
            ->assertCreated()
            // A transliterated address would be unreadable to the audience it is for.
            ->assertJsonPath('data.slug', 'كيف-تدير-مغسلتك');
    }

    public function test_a_second_article_with_the_same_title_gets_its_own_address(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/blog/posts', ['title' => 'دليل التسعير', 'content' => 'أ'])->assertCreated();
        $this->postJson('/api/admin/blog/posts', ['title' => 'دليل التسعير', 'content' => 'ب'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'دليل-التسعير-2');
    }

    public function test_publishing_stamps_the_date_once_and_keeps_it(): void
    {
        Sanctum::actingAs($this->owner());
        $post = BlogPost::factory()->create();

        $published = $this->putJson("/api/admin/blog/posts/{$post->getKey()}", ['status' => 'published'])
            ->assertOk()
            ->json('data.published_at');

        $this->assertNotNull($published);

        // Taken down and put back up: the article is not pretending it was written today.
        $this->postJson("/api/admin/blog/posts/{$post->getKey()}/archive")->assertOk();
        $this->putJson("/api/admin/blog/posts/{$post->getKey()}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.published_at', $published);
    }

    public function test_editing_the_status_alone_keeps_the_article(): void
    {
        Sanctum::actingAs($this->owner());
        $post = BlogPost::factory()->create(['content' => 'النص الأصلي']);

        $this->putJson("/api/admin/blog/posts/{$post->getKey()}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('data.content', 'النص الأصلي');
    }

    public function test_a_live_articles_address_is_not_rewritten_by_an_unrelated_edit(): void
    {
        Sanctum::actingAs($this->owner());
        $post = BlogPost::factory()->published()->create(['slug' => 'دليل-التسعير', 'title' => 'دليل التسعير']);

        // Links to a published piece are out in the world; renaming it silently breaks them.
        $this->putJson("/api/admin/blog/posts/{$post->getKey()}", ['excerpt' => 'ملخّص جديد'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'دليل-التسعير');
    }

    public function test_archiving_takes_the_article_down_without_destroying_it(): void
    {
        Sanctum::actingAs($this->owner());
        $post = BlogPost::factory()->published()->create();

        $this->postJson("/api/admin/blog/posts/{$post->getKey()}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', BlogPostStatusEnum::Archived->value);

        $this->assertDatabaseHas('blog_posts', ['id' => $post->getKey()]);
    }

    public function test_the_desk_shows_the_drafts_a_reader_never_sees(): void
    {
        Sanctum::actingAs($this->owner());
        BlogPost::factory()->create();
        BlogPost::factory()->published()->create();

        $this->getJson('/api/admin/blog/posts')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            // The editor is built from one response.
            ->assertJsonStructure(['data' => ['categories']]);
    }

    public function test_tags_are_cleaned_of_blanks_and_duplicates(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/blog/posts', [
            'title' => 'دليل التسعير',
            'content' => 'النص',
            // The blank one reaches the rules as null — the base request nulls empty
            // input before validation.
            'tags' => ['تسعير', ' تسعير ', '  ', 'أرباح'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.tags', ['تسعير', 'أرباح']);
    }

    public function test_writing_the_blog_needs_the_marketing_grant(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->postJson('/api/admin/blog/posts', ['title' => 'دليل التسعير', 'content' => 'النص'])
            ->assertStatus(403);
    }

    public function test_a_marketing_admin_may_write(): void
    {
        Sanctum::actingAs($this->marketingAdmin());

        $this->postJson('/api/admin/blog/posts', ['title' => 'دليل التسعير', 'content' => 'النص'])
            ->assertCreated();
    }

    public function test_a_tenant_staff_member_cannot_reach_the_desk(): void
    {
        $this->actingAsReader();

        $this->getJson('/api/admin/blog/posts')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function actingAsReader(): User
    {
        return $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    /**
     * A platform admin with no grants at all.
     */
    private function viewer(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();

        return $user;
    }

    private function marketingAdmin(): User
    {
        $user = $this->viewer();
        $user->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageMarketing->value]);

        return $user;
    }
}
