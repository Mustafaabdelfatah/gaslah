<?php

namespace App\Http\Requests\Community;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Open a forum thread.
 *
 * The forum is platform-wide, so the category is not tenant-scoped — it only has to be one
 * that is currently open for posting.
 */
class StoreForumThreadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('forum_categories', 'id')->where('is_active', true),
            ],
            'title' => ['required', 'string', 'min:3', 'max:300'],
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => __('api.forum_category_invalid'),
        ];
    }
}
