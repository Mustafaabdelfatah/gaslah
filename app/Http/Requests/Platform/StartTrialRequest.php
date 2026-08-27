<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use App\Models\PlatformPlan;
use Illuminate\Validation\Rule;

/**
 * Start (or restart) a tenant's free trial. Omitting the plan falls back to the cheapest
 * active one.
 */
class StartTrialRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
        ];
    }

    public function plan(): ?PlatformPlan
    {
        return $this->filled('plan_id')
            ? PlatformPlan::query()->find($this->integer('plan_id'))
            : null;
    }
}
