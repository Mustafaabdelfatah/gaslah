<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\OrgAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization announcements shown to customers in the portal. A customer-facing brand
 * surface, so writes are manager-gated.
 */
class OrgAnnouncementController extends TenantController
{
    public function index(): JsonResponse
    {
        $this->staff();

        return successResponse(
            OrgAnnouncement::query()->forOrganization($this->organizationId())->latest('id')->limit(100)->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireManager();

        $announcement = OrgAnnouncement::query()->create([
            ...$this->validated($request),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse($announcement, __('api.created_success'), 201);
    }

    public function update(Request $request, OrgAnnouncement $announcement): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($announcement);

        $announcement->update($this->validated($request, updating: true));

        return successResponse($announcement->refresh(), __('api.updated_success'));
    }

    public function destroy(OrgAnnouncement $announcement): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($announcement);

        $announcement->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'min:2', 'max:200'],
            'body' => [$required, 'string', 'max:1000'],
            'image_url' => ['nullable', 'string', 'regex:#^(https?://|/)#', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function assertOwned(OrgAnnouncement $announcement): void
    {
        abort_unless($announcement->organization_id === $this->organizationId(), 404, __('api.record_not_found'));
    }
}
