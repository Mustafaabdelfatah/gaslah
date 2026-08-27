<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * A stocked item.
 *
 * The unit must be one of the tenant's own, which is a rule rather than a controller
 * check — a unit from another tenant is a bad field, not a missing record.
 */
class InventoryItemRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $required = $this->route('item') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'unit_id' => [
                $required,
                'integer',
                Rule::exists('units', 'id')->where('organization_id', $this->organizationId()),
            ],
            'sku' => ['nullable', 'string', 'max:80'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_id.exists' => __('api.inventory_unit_not_in_org'),
        ];
    }
}
