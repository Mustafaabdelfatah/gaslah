<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\Organization;
use App\Models\PlatformCoupon;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Services\Platform\PlatformSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin control of an organization's subscription (manage_subscriptions).
 */
class AdminSubscriptionController extends PlatformBaseController
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'status' => ['required', 'in:trial,active,past_due,cancelled'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['nullable', 'boolean'],
            'custom_price' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $plan = PlatformPlan::query()->findOrFail($data['plan_id']);
        $status = PlatformSubscriptionStatusEnum::from($data['status']);
        $cycle = PlatformCycleEnum::from($data['cycle']);
        $periodEnd = isset($data['current_period_end']) ? Carbon::parse($data['current_period_end']) : null;
        $cancel = (bool) ($data['cancel_at_period_end'] ?? false);
        $customPrice = isset($data['custom_price']) ? (float) $data['custom_price'] : null;

        if (! empty($data['coupon_code'])) {
            $subscription = $this->applyWithCoupon($organization, $plan, $status, $cycle, $periodEnd, $cancel, $customPrice, $data['coupon_code']);
        } else {
            $subscription = $this->subscriptions->set($organization, $plan, $status, $cycle, $periodEnd, $cancel, $customPrice);
        }

        return successResponse($subscription->load('plan'), __('api.updated_success'));
    }

    /**
     * Set a subscription while redeeming a coupon: the discount adjusts the price and/or
     * extends the period, and the redemption is atomic — either the coupon is consumed and
     * the subscription set, or neither happens (422).
     */
    private function applyWithCoupon(
        Organization $organization,
        PlatformPlan $plan,
        PlatformSubscriptionStatusEnum $status,
        PlatformCycleEnum $cycle,
        ?Carbon $periodEnd,
        bool $cancel,
        ?float $customPrice,
        string $couponCode,
    ): PlatformSubscription {
        $coupon = PlatformCoupon::query()->where('code', strtoupper($couponCode))->first();

        abort_if($coupon === null || ! $coupon->isRedeemable($plan->getKey()), 422, __('api.coupon_not_redeemable'));

        $basePrice = $customPrice ?? (float) ($cycle === PlatformCycleEnum::Yearly ? $plan->yearly_price : $plan->monthly_price);
        $effect = $coupon->effect($basePrice);

        $effectivePeriodEnd = ($periodEnd ?? Carbon::now()->addMonths($cycle->months()))
            ->copy()
            ->addMonths($effect['extra_months']);

        return DB::transaction(function () use ($coupon, $organization, $plan, $status, $cycle, $effectivePeriodEnd, $cancel, $effect) {
            abort_unless($coupon->redeem(), 422, __('api.coupon_not_redeemable'));

            return $this->subscriptions->set($organization, $plan, $status, $cycle, $effectivePeriodEnd, $cancel, $effect['price']);
        });
    }

    public function startTrial(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate(['plan_id' => ['nullable', 'integer']]);
        $plan = isset($data['plan_id']) ? PlatformPlan::query()->find($data['plan_id']) : null;

        return successResponse($this->subscriptions->startTrial($organization, $plan)->load('plan'), __('api.updated_success'));
    }

    public function extend(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:3650']]);

        $subscription = $organization->platformSubscription;
        abort_if($subscription === null, 422, __('api.subscription_none_active'));

        return successResponse($this->subscriptions->extend($subscription, (int) $data['days']), __('api.updated_success'));
    }
}
