<?php

namespace App\Http\Requests\Community;

use App\Http\Requests\BaseFormRequest;

/**
 * A reply on a forum thread.
 */
class StoreForumPostRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
