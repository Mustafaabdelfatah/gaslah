<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Platform\ArchiveTenantRequest;
use App\Http\Resources\Platform\TenantDetailResource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\TenantDataService;
use Illuminate\Http\JsonResponse;

/**
 * Taking a tenant out of circulation, bringing it back, and handing its data over.
 *
 * There is no delete route, by design: every tenant shares one database, and removing an
 * organization row would cascade through orders, invoices and accounting entries that the
 * platform's own books reference. Archiving is the end of the line.
 *
 * The export is the platform owner's alone — it is the personal data of the customers a
 * laundry serves — and permissions are enforced on the routes.
 */
class AdminTenantDataController extends BaseController
{
    public function __construct(private readonly TenantDataService $data)
    {
        parent::__construct();
    }

    public function archive(ArchiveTenantRequest $request, Organization $organization): JsonResponse
    {
        $organization = $this->data->archive($organization, $this->admin(), $request->reason());

        return successResponse(new TenantDetailResource($organization), __('api.updated_success'));
    }

    public function unarchive(Organization $organization): JsonResponse
    {
        $organization = $this->data->unarchive($organization, $this->admin());

        return successResponse(new TenantDetailResource($organization), __('api.updated_success'));
    }

    /**
     * The tenant's own data as a bundle, with an explicit note when it was cut short.
     */
    public function export(Organization $organization): JsonResponse
    {
        return successResponse($this->data->export($organization, $this->admin()));
    }

    /**
     * The acting platform admin. The route middleware has already proven the session, so
     * this only narrows the type for the service call.
     */
    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
