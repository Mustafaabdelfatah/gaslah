<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Http\Requests\BaseFormRequest;
use App\Models\PlatformPlan;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Set (create or replace) an organization's platform subscription.
 *
 * The typed accessors keep the enum/date coercion beside the rules that guarantee it,
 * so the service receives values it can trust rather than raw input.
 */
class SetSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('platform_plans', 'id')],
            'status' => ['required', Rule::in(PlatformSubscriptionStatusEnum::values())],
            'cycle' => ['required', Rule::in(PlatformCycleEnum::values())],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['nullable', 'boolean'],
            'custom_price' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function plan(): PlatformPlan
    {
        return PlatformPlan::query()->findOrFail($this->integer('plan_id'));
    }

    public function status(): PlatformSubscriptionStatusEnum
    {
        return PlatformSubscriptionStatusEnum::from($this->string('status')->toString());
    }

    public function cycle(): PlatformCycleEnum
    {
        return PlatformCycleEnum::from($this->string('cycle')->toString());
    }

    public function currentPeriodEnd(): ?Carbon
    {
        return $this->filled('current_period_end')
            ? Carbon::parse($this->input('current_period_end'))
            : null;
    }

    public function cancelAtPeriodEnd(): bool
    {
        return $this->booleanInput('cancel_at_period_end');
    }

    public function customPrice(): ?float
    {
        return $this->filled('custom_price') ? (float) $this->input('custom_price') : null;
    }

    public function couponCode(): ?string
    {
        $code = $this->input('coupon_code');

        return empty($code) ? null : (string) $code;
    }
}
