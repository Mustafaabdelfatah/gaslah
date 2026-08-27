<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\ActiveFilter;
use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\CouponFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformCouponRequest;
use App\Http\Requests\Platform\ValidateCouponRequest;
use App\Http\Resources\Platform\PlatformCouponResource;
use App\Models\PlatformCoupon;
use App\Trait\Global\HasDeleteMethods;
use App\Trait\Global\HasToggleActiveMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * Subscription coupons. Redemption itself happens as part of setting a subscription
 * (see PlatformSubscriptionService::apply); this surface only defines and previews them.
 */
class AdminCouponController extends BaseController
{
    use HasDeleteMethods, HasToggleActiveMethods;

    public function __construct()
    {
        parent::__construct();
        $this->model = PlatformCoupon::class;

        // Platform admins are authorised by the route middleware, not by a per-model
        // policy or a spatie permission.
        $this->enableDeletePolicy(false)->enableTogglePolicy(false);
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformCoupon::query()->with('plan:id,name'))
            ->through([CouponFilter::class, ActiveFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformCouponResource::class));
    }

    public function store(PlatformCouponRequest $request): JsonResponse
    {
        $coupon = PlatformCoupon::create($request->validated());

        return successResponse(new PlatformCouponResource($coupon->refresh()), __('api.created_success'), 201);
    }

    public function show(PlatformCoupon $coupon): JsonResponse
    {
        return successResponse(new PlatformCouponResource($coupon->load('plan:id,name')));
    }

    public function update(PlatformCouponRequest $request, PlatformCoupon $coupon): JsonResponse
    {
        $coupon->update($request->validated());

        return successResponse(new PlatformCouponResource($coupon->refresh()), __('api.updated_success'));
    }

    /**
     * Preview a code before it is applied to a subscription.
     */
    public function validateCode(ValidateCouponRequest $request): JsonResponse
    {
        $coupon = $request->coupon();

        return successResponse([
            'found' => $coupon !== null,
            'redeemable' => $coupon?->isRedeemable($request->planId()) ?? false,
            'coupon' => $coupon === null ? null : new PlatformCouponResource($coupon),
        ]);
    }
}
