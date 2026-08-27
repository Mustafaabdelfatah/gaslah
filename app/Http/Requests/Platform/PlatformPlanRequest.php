<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan create/update rules.
 *
 * On update every field is optional: an omitted field keeps its stored value rather than
 * being cleared, so a partial edit cannot silently retire a plan or wipe its feature keys.
 */
class PlatformPlanRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->isUpdating() ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],

            'monthly_price' => [$required, 'numeric', 'min:0'],
            'yearly_price' => [$required, 'numeric', 'min:0'],

            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],

            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:255'],

            'feature_keys' => ['nullable', 'array'],
            'feature_keys.*' => ['string', Rule::exists('features', 'key')],

            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function isUpdating(): bool
    {
        return $this->route('plan') !== null;
    }
}
