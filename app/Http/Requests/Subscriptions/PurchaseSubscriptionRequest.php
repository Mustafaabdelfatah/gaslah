<?php

namespace App\Http\Requests\Subscriptions;

use App\Http\Requests\Tenancy\TenantFormRequest;
use App\Models\Customer;
use App\Models\SubscriptionPlan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sell a customer package to a customer.
 *
 * The rules only check shape. Ownership is settled by the accessors below, which answer
 * 404 for anything outside the caller's tenant — the same answer the rest of the
 * application gives, and one that does not confirm the record exists elsewhere.
 */
class PurchaseSubscriptionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
        ];
    }

    public function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->forOrganization($this->organizationId())
            ->findOr($this->integer('plan_id'), fn () => abort(Response::HTTP_NOT_FOUND, __('api.subscription_plan_not_found')));
    }

    public function customer(): Customer
    {
        return Customer::query()
            ->forOrganization($this->organizationId())
            ->findOrFail($this->integer('customer_id'));
    }
}
