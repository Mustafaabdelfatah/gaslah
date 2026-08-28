<?php

namespace App\Http\Requests\Market;

use App\Enum\Market\MarketCategoryEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A product a supplier lists or edits.
 *
 * Stock is nullable and means unlimited, so a supplier who does not count stock is not
 * forced to claim a number.
 */
class MarketProductRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->route('product') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'category' => [$required, Rule::in(MarketCategoryEnum::values())],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'price' => [$required, 'numeric', 'min:0', 'max:1000000'],
            'stock' => ['nullable', 'integer', 'min:0'],
            // Either an absolute URL or a root-relative path; nothing else can be rendered
            // safely by a client that did not upload it.
            'image_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
