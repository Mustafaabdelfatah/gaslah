<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use App\Rules\GatedFeatureKeys;

/**
 * Per-tenant entitlement overrides: gated feature switches and seat/branch ceilings.
 *
 * The update is partial by design — only the keys actually sent are written, so raising a
 * seat ceiling cannot silently wipe the feature overrides.
 */
class UpdateEntitlementsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'feature_overrides' => ['nullable', 'array', new GatedFeatureKeys],
            'feature_overrides.*' => ['boolean'],
            'max_branches_override' => ['nullable', 'integer', 'min:1'],
            'max_users_override' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The overrides to write, limited to the fields the caller actually sent.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $updates = [];

        if ($this->has('feature_overrides')) {
            $updates['feature_overrides'] = collect($this->input('feature_overrides') ?? [])
                ->map(fn ($enabled) => filter_var($enabled, FILTER_VALIDATE_BOOLEAN))
                ->all();
        }

        foreach (['max_branches_override', 'max_users_override'] as $limit) {
            if ($this->has($limit)) {
                $updates[$limit] = $this->input($limit);
            }
        }

        return $updates;
    }
}
