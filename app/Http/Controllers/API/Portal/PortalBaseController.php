<?php

namespace App\Http\Controllers\API\Portal;

use App\Http\Controllers\API\BaseController;
use App\Models\Customer;

/**
 * Base for the customer portal. The authenticated principal is a Customer (kind=customer
 * surface); a staff or driver token can never reach a portal route.
 */
abstract class PortalBaseController extends BaseController
{
    /**
     * The authenticated customer.
     */
    protected function customer(): Customer
    {
        $customer = request()->user();

        abort_unless($customer instanceof Customer, 401, __('api.unauthorized'));

        return $customer;
    }
}
