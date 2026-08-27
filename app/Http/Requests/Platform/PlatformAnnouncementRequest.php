<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A platform → tenant broadcast banner.
 *
 * A null organization_id targets every tenant; a value confines the banner to one. On
 * update every field is optional so a partial edit keeps what it did not mention.
 */
class PlatformAnnouncementRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->route('announcement') !== null ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:200'],
            'body' => [$required, 'string', 'max:5000'],
            'level' => ['nullable', Rule::in(PlatformAnnouncementLevelEnum::values())],
            'organization_id' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'is_active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }
}
