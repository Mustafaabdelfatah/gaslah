<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Blog\BlogPostStatusEnum;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Blog\BlogPostRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Blog\AdminBlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use App\Services\Blog\BlogPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator's desk for the platform blog. Gated on manage_marketing at the routes.
 *
 * Unlike the reader's endpoints this one shows drafts, archived pieces and articles dated
 * forward — everything the author needs to see to work on it.
 */
class AdminBlogController extends BaseController
{
    public function __construct(private readonly BlogPostService $posts)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = BlogPost::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with('category:id,name,slug')
            ->latest('id');

        return successResponse(wrapPaginate($query, AdminBlogPostResource::class, [
            // The categories ride along so the editor can be built from one response.
            'categories' => BlogCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug']),
        ]));
    }

    public function show(BlogPost $post): JsonResponse
    {
        return successResponse(new AdminBlogPostResource($post->load('category:id,name,slug')));
    }

    public function store(BlogPostRequest $request): JsonResponse
    {
        /** @var User $author */
        $author = $request->user();

        $post = $this->posts->create(
            $request->attributesForWrite(),
            $request->status(),
            $request->slugSource(),
            $author,
        );

        return successResponse(
            new AdminBlogPostResource($post->load('category:id,name,slug')),
            __('api.created_success'),
            201,
        );
    }

    public function update(BlogPostRequest $request, BlogPost $post): JsonResponse
    {
        $post = $this->posts->update(
            $post,
            $request->attributesForWrite(),
            $request->status(),
            $request->filled('slug') ? $request->string('slug')->toString() : null,
        );

        return successResponse(
            new AdminBlogPostResource($post->load('category:id,name,slug')),
            __('api.updated_success'),
        );
    }

    /**
     * Take an article down. Archiving rather than deleting: a published piece has links
     * pointing at it, and the operator may want it back.
     */
    public function archive(BlogPost $post): JsonResponse
    {
        $post->update(['status' => BlogPostStatusEnum::Archived->value]);

        return successResponse(new AdminBlogPostResource($post->refresh()), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */
    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = BlogCategory::query()->create([
            ...$data,
            'slug' => $this->posts->uniqueCategorySlug($this->posts->slugify($data['name'])),
        ]);

        return successResponse($category, __('api.created_success'), 201);
    }
}
