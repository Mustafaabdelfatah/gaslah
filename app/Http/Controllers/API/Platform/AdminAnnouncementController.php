<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\ActiveFilter;
use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\OrganizationScopeFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformAnnouncementRequest;
use App\Http\Resources\Platform\PlatformAnnouncementResource;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Trait\Global\HasDeleteMethods;
use App\Trait\Global\HasToggleActiveMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * Platform → tenant broadcast banners. Gated on manage_announcements at the routes.
 */
class AdminAnnouncementController extends BaseController
{
    use HasDeleteMethods, HasToggleActiveMethods;

    public function __construct()
    {
        parent::__construct();
        $this->model = PlatformAnnouncement::class;

        // Platform admins are authorised by the route middleware, not a per-model policy.
        $this->enableDeletePolicy(false)->enableTogglePolicy(false);
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformAnnouncement::query()->with('organization:id,name'))
            ->through([OrganizationScopeFilter::class, ActiveFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformAnnouncementResource::class));
    }

    public function store(PlatformAnnouncementRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $announcement = PlatformAnnouncement::create([
            ...$request->attributesForWrite(),
            'created_by_id' => $admin->getKey(),
        ]);

        return successResponse(
            new PlatformAnnouncementResource($announcement->refresh()),
            __('api.created_success'),
            201,
        );
    }

    public function show(PlatformAnnouncement $announcement): JsonResponse
    {
        return successResponse(new PlatformAnnouncementResource($announcement->load('organization:id,name')));
    }

    public function update(PlatformAnnouncementRequest $request, PlatformAnnouncement $announcement): JsonResponse
    {
        $announcement->update($request->attributesForWrite());

        return successResponse(new PlatformAnnouncementResource($announcement->refresh()), __('api.updated_success'));
    }
}
