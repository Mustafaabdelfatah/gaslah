<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\API\BaseController;
use App\Models\Affiliate;

/**
 * Base for the affiliate surface. The authenticated principal is an Affiliate
 * (kind=affiliate); a staff/customer/driver token can never act here.
 */
abstract class AffiliateBaseController extends BaseController
{
    protected function affiliate(): Affiliate
    {
        $affiliate = request()->user();

        abort_unless($affiliate instanceof Affiliate, 401, __('api.unauthorized'));
        abort_unless($affiliate->is_active, 403, __('api.unauthorized'));

        return $affiliate;
    }
}
