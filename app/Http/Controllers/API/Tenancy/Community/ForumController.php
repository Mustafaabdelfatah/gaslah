<?php

namespace App\Http\Controllers\API\Tenancy\Community;

use App\Enum\Forum\ForumStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The platform-wide community forum. Reads require an authenticated staff member; a new
 * thread starts Pending (moderated), while replies are post-moderated (auto-approved).
 */
class ForumController extends TenantController
{
    private const PENDING_QUEUE_LIMIT = 3;

    public function categories(): JsonResponse
    {
        $this->staff();

        return successResponse(ForumCategory::query()->where('is_active', true)->orderBy('sort_order')->get());
    }

    public function threads(Request $request): JsonResponse
    {
        $this->staff();

        $threads = ForumThread::query()
            ->where('status', ForumStatusEnum::Approved->value)
            ->when($request->filled('category'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->input('category'))))
            ->with(['author:id,name', 'category:id,name,slug'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();

        return successResponse($threads);
    }

    public function show(ForumThread $thread): JsonResponse
    {
        $this->staff();
        abort_unless($thread->status === ForumStatusEnum::Approved, 404, __('api.record_not_found'));

        $thread->increment('view_count');
        $thread->load([
            'author:id,name',
            'category:id,name,slug',
            'posts' => fn ($q) => $q->where('status', ForumStatusEnum::Approved->value)->with('author:id,name')->orderBy('id'),
        ]);

        return successResponse($thread);
    }

    public function storeThread(Request $request): JsonResponse
    {
        $user = $this->staff();

        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ]);

        $category = ForumCategory::query()->where('is_active', true)->find($data['category_id']);
        abort_if($category === null, 422, __('api.forum_category_invalid'));

        // A moderation-queue cap per author.
        $pending = ForumThread::query()->where('author_id', $user->getKey())->where('status', ForumStatusEnum::Pending->value)->count();
        abort_if($pending >= self::PENDING_QUEUE_LIMIT, 429, __('api.forum_pending_limit'));

        $thread = ForumThread::query()->create([
            'organization_id' => $this->organizationId(),
            'category_id' => $category->getKey(),
            'title' => $data['title'],
            'slug' => $this->slug($data['title']),
            'body' => $data['body'],
            'author_id' => $user->getKey(),
            'status' => ForumStatusEnum::Pending->value,
            'last_activity_at' => Carbon::now(),
        ]);

        return successResponse($thread, __('api.forum_thread_pending'), 201);
    }

    public function storePost(Request $request, ForumThread $thread): JsonResponse
    {
        $user = $this->staff();

        abort_unless($thread->status === ForumStatusEnum::Approved, 404, __('api.record_not_found'));
        abort_if($thread->is_closed, 422, __('api.forum_thread_closed'));

        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:10000']]);

        $post = ForumPost::query()->create([
            'thread_id' => $thread->getKey(),
            'author_id' => $user->getKey(),
            'body' => $data['body'],
            'status' => ForumStatusEnum::Approved->value,
        ]);

        $thread->increment('reply_count');
        $thread->forceFill(['last_activity_at' => Carbon::now()])->save();

        return successResponse($post->load('author:id,name'), __('api.created_success'), 201);
    }

    /**
     * A slug that preserves Arabic letters.
     */
    private function slug(string $title): string
    {
        $slug = preg_replace('/\s+/u', '-', trim(mb_strtolower($title)));
        $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $slug ?? '');

        return trim($slug ?? '', '-') ?: (string) Carbon::now()->timestamp;
    }
}
