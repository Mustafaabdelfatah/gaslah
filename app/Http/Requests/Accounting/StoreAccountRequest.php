<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\AccountTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreAccountRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'type' => ['required', new Enum(AccountTypeEnum::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
        ];
    }
}
