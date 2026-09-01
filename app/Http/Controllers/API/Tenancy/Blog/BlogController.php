<?php

namespace App\Http\Controllers\API\Tenancy\Blog;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Resources\Blog\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's own publication, as a laundry reads it.
 *
 * The blog is platform-wide: the same articles for every tenant, written by the operator.
 * Reads require an authenticated staff member — this is a surface inside the dashboard,
 * not a public marketing site.
 */
class BlogController extends TenantController
{
    public function categories(): JsonResponse
    {
        $this->staff();

        return successResponse(
            BlogCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->staff();

        $query = BlogPost::query()
            ->readable()
            ->when($request->filled('category'), fn ($q) => $q->whereHas(
                'category',
                fn ($c) => $c->where('slug', $request->input('category')),
            ))
            ->with('category:id,name,slug')
            ->orderByDesc('published_at');

        return successResponse(wrapPaginate($query, BlogPostResource::class));
    }

    /**
     * One article, addressed by its slug. A draft, an archived post and one scheduled for
     * next week are all a 404 — an unpublished article does not exist to a reader.
     */
    public function show(string $slug): JsonResponse
    {
        $this->staff();

        $post = BlogPost::query()
            ->readable()
            ->where('slug', $slug)
            ->with('category:id,name,slug')
            ->first();

        abort_if($post === null, 404, __('api.record_not_found'));

        // Counted without touching updated_at: a read is not an edit.
        BlogPost::query()->whereKey($post->getKey())->increment('view_count');

        return successResponse((new BlogPostResource($post))->asDetail());
    }
}
