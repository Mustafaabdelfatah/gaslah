<?php

namespace App\Http\Controllers\API\Market;

use App\Http\Controllers\API\BaseController;
use App\Models\MarketSupplier;

/**
 * Base for the market supplier portal. The authenticated principal is a MarketSupplier
 * (kind=supplier); a staff, customer, driver or affiliate token can never act here.
 *
 * Every query in this portal is scoped by the id this returns, which is what keeps one
 * supplier from reading another's catalogue or orders.
 */
abstract class SupplierBaseController extends BaseController
{
    protected function supplier(): MarketSupplier
    {
        $supplier = request()->user();

        abort_unless($supplier instanceof MarketSupplier, 401, __('api.unauthorized'));

        // A rejected account keeps its token until it expires; the check is repeated here
        // so revoking approval takes effect on the next request, not on the next sign-in.
        abort_unless($supplier->status->canSignIn(), 403, __('api.market_supplier_rejected'));

        return $supplier;
    }
}
