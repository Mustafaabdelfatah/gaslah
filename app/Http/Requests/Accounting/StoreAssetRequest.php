<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\AssetAcquisitionSourceEnum;
use App\Enum\Accounting\AssetCategoryEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAssetRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', new Enum(AssetCategoryEnum::class)],
            'cost' => ['required', 'numeric', 'gt:0'],
            'purchase_date' => ['required', 'date'],
            'useful_life_months' => ['required', 'integer', 'between:1,600'],
            'salvage_value' => ['nullable', 'numeric', 'min:0', 'lt:cost'],
            'paid_from' => ['nullable', new Enum(AssetAcquisitionSourceEnum::class)],
            'branch_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
