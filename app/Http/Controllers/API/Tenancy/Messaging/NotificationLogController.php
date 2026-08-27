<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Messaging\NotificationLogResource;
use App\Models\WaMessage;
use Illuminate\Http\JsonResponse;

/**
 * The organization's outbound message log (read-only), sourced from wa_messages.
 */
class NotificationLogController extends TenantController
{
    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = WaMessage::query()
            ->where('organization_id', $this->organizationId())
            ->with('customer:id,name,phone')
            ->latest('id');

        return successResponse(wrapPaginate($query, NotificationLogResource::class));
    }
}
