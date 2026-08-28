<?php

namespace App\Http\Controllers\API\Tenancy\Community;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Resources\Community\ForumThreadResource;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;

/**
 * The organization's community feed: a curated view over the global forum — the caller's
 * own threads (any status) plus the active categories for starting a new one.
 */
class CommunityController extends TenantController
{
    /**
     * How many of the author's own threads the panel shows.
     */
    private const FEED_SIZE = 50;

    public function feed(): JsonResponse
    {
        $user = $this->staff();

        // A personal feed panel, not a browsable archive: one author's own threads, most
        // recent first. The full listing is /forum/threads, which paginates.
        $mine = ForumThread::query()
            ->where('author_id', $user->getKey())
            ->with('category:id,name,slug')
            ->latest('id')
            ->limit(self::FEED_SIZE)
            ->get();

        return successResponse([
            'my_threads' => ForumThreadResource::collection($mine),
            'categories' => ForumCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }
}
