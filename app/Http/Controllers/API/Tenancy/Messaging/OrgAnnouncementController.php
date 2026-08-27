<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Messaging\OrgAnnouncementRequest;
use App\Http\Resources\Messaging\OrgAnnouncementResource;
use App\Models\OrgAnnouncement;
use Illuminate\Http\JsonResponse;

/**
 * Organization announcements shown to customers in the portal. A customer-facing brand
 * surface, so writes are manager-gated.
 */
class OrgAnnouncementController extends TenantController
{
    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = OrgAnnouncement::query()->forOrganization($this->organizationId())->latest('id');

        return successResponse(wrapPaginate($query, OrgAnnouncementResource::class));
    }

    public function store(OrgAnnouncementRequest $request): JsonResponse
    {

        $announcement = OrgAnnouncement::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse(new OrgAnnouncementResource($announcement), __('api.created_success'), 201);
    }

    public function update(OrgAnnouncementRequest $request, OrgAnnouncement $announcement): JsonResponse
    {
        $this->assertOwned($announcement);

        $announcement->update($request->validated());

        return successResponse(new OrgAnnouncementResource($announcement->refresh()), __('api.updated_success'));
    }

    public function destroy(OrgAnnouncement $announcement): JsonResponse
    {
        $this->assertOwned($announcement);

        $announcement->delete();

        return successResponse(msg: __('api.deleted_success'));
    }
}
