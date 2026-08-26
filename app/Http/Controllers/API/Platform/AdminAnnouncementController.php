<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\PlatformAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform → tenant broadcast banners (manage_announcements).
 */
class AdminAnnouncementController extends PlatformBaseController
{
    public function index(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageAnnouncements);

        return successResponse(
            PlatformAnnouncement::query()->with('organization:id,name')->latest()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageAnnouncements);

        $data = $this->validated($request);
        $data['created_by_id'] = $this->admin()->getKey();

        return successResponse(PlatformAnnouncement::query()->create($data), __('api.created_success'), 201);
    }

    public function update(Request $request, PlatformAnnouncement $announcement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageAnnouncements);

        $announcement->update($this->validated($request, partial: true));

        return successResponse($announcement, __('api.updated_success'));
    }

    public function destroy(PlatformAnnouncement $announcement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageAnnouncements);

        $announcement->delete();

        return successResponse(null, __('api.deleted_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:5000'],
            'level' => ['nullable', 'in:'.implode(',', PlatformAnnouncementLevelEnum::values())],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }
}
