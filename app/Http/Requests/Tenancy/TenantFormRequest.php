<?php

namespace App\Http\Requests\Tenancy;

use App\Http\Requests\BaseFormRequest;
use App\Services\Tenancy\TenantContext;

/**
 * Base for staff-facing form requests.
 *
 * Uniqueness inside a tenant is a validation rule, not a hand-rolled check in a
 * controller, and expressing it needs the caller's organization — so requests that scope
 * a rule reach for it here rather than each resolving the tenant context themselves.
 */
abstract class TenantFormRequest extends BaseFormRequest
{
    /**
     * The organization the caller belongs to.
     */
    protected function organizationId(): int
    {
        return app(TenantContext::class)->requireOrganizationId();
    }
}
