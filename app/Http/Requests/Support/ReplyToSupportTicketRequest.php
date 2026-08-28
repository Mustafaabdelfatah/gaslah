<?php

namespace App\Http\Requests\Support;

use App\Http\Requests\BaseFormRequest;

/**
 * A reply into a support thread, from either side.
 */
class ReplyToSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    public function body(): string
    {
        return $this->string('body')->trim()->toString();
    }
}
