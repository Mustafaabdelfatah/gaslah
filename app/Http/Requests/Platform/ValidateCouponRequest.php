<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use App\Models\PlatformCoupon;
use Illuminate\Validation\Rule;

/**
 * Preview whether a coupon could be redeemed, optionally against a specific plan.
 *
 * An unknown code is not a validation failure — the console wants "not found" as an
 * answer, not a 422.
 */
class ValidateCouponRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
        ];
    }

    public function coupon(): ?PlatformCoupon
    {
        return PlatformCoupon::query()
            ->where('code', mb_strtoupper($this->string('code')->toString()))
            ->first();
    }

    public function planId(): ?int
    {
        return $this->filled('plan_id') ? $this->integer('plan_id') : null;
    }
}
