<?php

namespace App\Http\Controllers\API\Tenancy\Community;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\ForumCategory;
use App\Models\ForumThread;
use Illuminate\Http\JsonResponse;

/**
 * The organization's community feed: a curated view over the global forum — the caller's
 * own threads (any status) plus the active categories for starting a new one.
 */
class CommunityController extends TenantController
{
    public function feed(): JsonResponse
    {
        $user = $this->staff();

        return successResponse([
            'my_threads' => ForumThread::query()
                ->where('author_id', $user->getKey())
                ->with('category:id,name,slug')
                ->latest('id')
                ->limit(50)
                ->get(['id', 'category_id', 'title', 'slug', 'status', 'reply_count', 'view_count', 'last_activity_at']),
            'categories' => ForumCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }
}
