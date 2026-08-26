<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformCouponTypeEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\PlatformCoupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD and validation for subscription coupons (manage_subscriptions). Coupons are
 * redeemed as part of setting a subscription; this surface only defines and previews them.
 */
class AdminCouponController extends PlatformBaseController
{
    public function index(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        return successResponse(
            PlatformCoupon::query()->with('plan:id,name')->latest('id')->paginate(30),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $this->validated($request);

        return successResponse(PlatformCoupon::query()->create($data), __('api.created_success'), 201);
    }

    public function update(Request $request, PlatformCoupon $coupon): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $coupon->update($this->validated($request, $coupon));

        return successResponse($coupon->fresh(), __('api.updated_success'));
    }

    public function destroy(PlatformCoupon $coupon): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $coupon->delete();

        return successResponse(null, __('api.deleted_success'));
    }

    /**
     * Preview whether a coupon can be redeemed (optionally for a given plan).
     */
    public function validateCode(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $coupon = PlatformCoupon::query()->where('code', strtoupper($data['code']))->first();
        $planId = $data['plan_id'] ?? null;

        return successResponse([
            'found' => $coupon !== null,
            'redeemable' => $coupon !== null && $coupon->isRedeemable($planId),
            'coupon' => $coupon,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlatformCoupon $coupon = null): array
    {
        $codeRule = 'unique:platform_coupons,code'.($coupon !== null ? ','.$coupon->getKey() : '');

        return $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', $codeRule],
            'type' => ['required', 'in:'.implode(',', PlatformCouponTypeEnum::values())],
            'value' => ['required', 'numeric', 'min:0'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'applies_to_plan_id' => ['nullable', 'integer', 'exists:platform_plans,id'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
