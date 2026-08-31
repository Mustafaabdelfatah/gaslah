<?php

namespace App\Http\Controllers\API\Tenancy\Platform;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Resources\Platform\OrgEntitlementsResource;
use App\Http\Resources\Platform\OrgSubscriptionInvoiceResource;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Http\Resources\Platform\PlatformSubscriptionResource;
use App\Models\PlatformPlan;
use App\Models\SubscriptionInvoice;
use Illuminate\Http\JsonResponse;

/**
 * The organization's view of its own entitlements and subscription.
 */
class OrgSubscriptionController extends TenantController
{
    /**
     * A ceiling on the billing history. A tenant reviewing years of invoices wants a
     * statement, not a screen; the operator console is where that lives.
     */
    private const INVOICE_CAP = 60;

    /**
     * The entitlements snapshot — readable by any staff member, since it drives feature
     * gating and the account-status banner.
     */
    public function entitlements(): JsonResponse
    {
        $organization = $this->organization();
        $organization->loadMissing('platformSubscription.plan');

        return successResponse(
            new OrgEntitlementsResource($organization, $this->entitlements->snapshot($organization)),
        );
    }

    /**
     * The subscription in full, with the plans available to move to — manager only.
     */
    public function subscription(): JsonResponse
    {

        $subscription = $this->organization()->platformSubscription?->load('plan');

        return successResponse([
            'subscription' => $subscription === null ? null : new PlatformSubscriptionResource($subscription),
            'plans' => PlatformPlanResource::collection(
                PlatformPlan::query()->active()->orderBy('sort_order')->get(),
            ),
            // Only issued invoices: a draft is the platform's working document, and a
            // tenant billed for one that was never confirmed would be owed an answer
            // nobody could give.
            'invoices' => OrgSubscriptionInvoiceResource::collection(
                SubscriptionInvoice::query()
                    ->where('organization_id', $this->organizationId())
                    ->issued()
                    ->latest('id')
                    ->limit(self::INVOICE_CAP)
                    ->get(),
            ),
        ]);
    }
}
