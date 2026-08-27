<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\PlatformCouponTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Coupon create/update rules.
 *
 * The code is compared case-insensitively because it is stored upper-cased, so "save20"
 * and "SAVE20" are the same coupon and cannot both be created.
 */
class PlatformCouponRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $couponId = $this->route('coupon')?->getKey();

        return [
            'code' => [
                'required',
                'string',
                'min:2',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('platform_coupons', 'code')->ignore($couponId),
            ],
            'type' => ['required', Rule::in(PlatformCouponTypeEnum::values())],
            'value' => ['required', 'numeric', 'min:0', $this->percentCeiling()],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'applies_to_plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper((string) $this->input('code'))]);
        }
    }

    /**
     * A percentage coupon cannot discount more than the whole price.
     */
    private function percentCeiling(): string
    {
        return $this->input('type') === PlatformCouponTypeEnum::Percent->value ? 'max:100' : 'max:1000000';
    }
}
