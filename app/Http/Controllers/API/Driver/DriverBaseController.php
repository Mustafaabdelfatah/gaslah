<?php

namespace App\Http\Controllers\API\Driver;

use App\Http\Controllers\API\BaseController;
use App\Models\DeliveryRequest;
use App\Models\Driver;

/**
 * Base for the driver app. The authenticated principal is a Driver (its own Sanctum
 * surface), never a staff user, and an inactive driver is refused immediately.
 */
abstract class DriverBaseController extends BaseController
{
    /**
     * The authenticated, active driver.
     */
    protected function driver(): Driver
    {
        $driver = request()->user();

        // Only a driver-kind token carries a Driver tokenable; a staff/customer token can
        // never act here.
        abort_unless($driver instanceof Driver, 401, __('api.unauthorized'));
        abort_unless($driver->is_active, 403, __('api.driver_inactive'));

        return $driver;
    }

    /**
     * A delivery request assigned to the authenticated driver, or 404.
     */
    protected function ownedRequest(int $id): DeliveryRequest
    {
        $request = DeliveryRequest::query()
            ->where('driver_id', $this->driver()->getKey())
            ->find($id);

        abort_if($request === null, 404, __('api.record_not_found'));

        return $request;
    }
}
