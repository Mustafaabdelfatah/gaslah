<?php

namespace App\Http\Requests\Crm;

use App\Enum\Crm\LeadStageEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a lead, including moving it along the pipeline.
 */
class UpdateLeadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:60'],
            'expected_mrr' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_platform_owner', true)],

            'stage' => ['nullable', Rule::in(LeadStageEnum::values())],

            // A lost deal has to say why. Losing without a reason teaches the operator
            // nothing, which is the whole point of recording it.
            'lost_reason' => [
                Rule::requiredIf(fn (): bool => $this->input('stage') === LeadStageEnum::Lost->value),
                'nullable', 'string', 'max:500',
            ],
        ];
    }
}
