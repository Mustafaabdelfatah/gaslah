<?php

namespace Tests\Feature\Community;

use App\Enum\Forum\ForumStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForumApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private ForumCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        $this->category = ForumCategory::factory()->create();
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
    }

    public function test_a_new_thread_starts_pending_and_is_not_listed(): void
    {
        $this->postJson('/api/forum/threads', ['category_id' => $this->category->getKey(), 'title' => 'كيف أزيد المبيعات', 'body' => 'سؤال مهم'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        // Pending threads are not in the public list.
        $this->getJson('/api/forum/threads')->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_the_pending_queue_is_capped(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forum/threads', ['category_id' => $this->category->getKey(), 'title' => "موضوع {$i} للنقاش", 'body' => 'نص'])->assertCreated();
        }

        // The fourth pending thread is refused.
        $this->postJson('/api/forum/threads', ['category_id' => $this->category->getKey(), 'title' => 'موضوع رابع للنقاش', 'body' => 'نص'])
            ->assertStatus(429);
    }

    public function test_replies_require_an_approved_thread_and_are_auto_approved(): void
    {
        $approved = $this->approvedThread();

        $this->postJson("/api/forum/threads/{$approved->getKey()}/posts", ['body' => 'رد مفيد'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, $approved->fresh()->reply_count);

        // A reply to a pending thread is 404 (it isn't publicly visible).
        $pending = $this->approvedThread(['status' => ForumStatusEnum::Pending->value]);
        $this->postJson("/api/forum/threads/{$pending->getKey()}/posts", ['body' => 'x'])->assertStatus(404);
    }

    public function test_a_closed_thread_rejects_replies(): void
    {
        $thread = $this->approvedThread(['is_closed' => true]);

        $this->postJson("/api/forum/threads/{$thread->getKey()}/posts", ['body' => 'late'])->assertStatus(422);
    }

    public function test_the_community_feed_shows_my_threads(): void
    {
        $this->postJson('/api/forum/threads', ['category_id' => $this->category->getKey(), 'title' => 'موضوعي الخاص', 'body' => 'نص'])->assertCreated();

        $this->getJson('/api/community')->assertOk()->assertJsonCount(1, 'data.my_threads');
    }

    public function test_the_thread_detail_carries_the_body_and_its_replies(): void
    {
        $thread = $this->approvedThread();
        $this->postJson("/api/forum/threads/{$thread->getKey()}/posts", ['body' => 'رد مفيد'])->assertCreated();

        $response = $this->getJson("/api/forum/threads/{$thread->getKey()}")->assertOk();

        $response->assertJsonPath('data.body', 'نص')
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.body', 'رد مفيد')
            // The author is a name on the thread and on every reply. The listing says the
            // same thing the same way, so a client can render both from one shape.
            ->assertJsonPath('data.author', $this->currentUser()->name)
            ->assertJsonPath('data.replies.0.author', $this->currentUser()->name);
    }

    public function test_the_listing_ships_no_bodies(): void
    {
        $this->approvedThread();

        $row = $this->getJson('/api/forum/threads')->assertOk()->json('data.data.0');

        // A hundred threads must not mean a hundred full posts on the wire.
        $this->assertArrayNotHasKey('body', $row);
        $this->assertArrayNotHasKey('replies', $row);
        $this->assertSame($this->currentUser()->name, $row['author']);
    }

    public function test_a_pending_thread_cannot_be_opened(): void
    {
        $pending = $this->approvedThread(['status' => ForumStatusEnum::Pending->value]);

        // Moderation is not a soft hint: an unapproved thread does not exist to a reader.
        $this->getJson("/api/forum/threads/{$pending->getKey()}")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function approvedThread(array $attributes = []): ForumThread
    {
        return ForumThread::query()->create([
            'category_id' => $this->category->getKey(),
            'title' => 'موضوع معتمد',
            'slug' => 'thread',
            'body' => 'نص',
            'author_id' => $this->currentUser()->getKey(),
            'status' => ForumStatusEnum::Approved->value,
            'last_activity_at' => now(),
            ...$attributes,
        ]);
    }

    private function currentUser(): User
    {
        return auth()->user();
    }
}
