<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Http\Requests\BaseFormRequest;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Support\Carbon;
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
        $required = $this->isUpdate() ? 'sometimes' : 'required';

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

    /**
     * The banner's attributes, with the operator's own defaults filled in on create.
     *
     * Applied here rather than left to the column: a banner with no end date would run
     * for ever, and "for ever" is not what an operator means when they leave the field
     * blank on a maintenance notice.
     *
     * @return array<string, mixed>
     */
    public function attributesForWrite(): array
    {
        $validated = $this->validated();

        // An edit keeps whatever it did not mention, defaults included.
        if ($this->isUpdate()) {
            return $validated;
        }

        $defaults = app(PlatformSettingsService::class)->announcements();

        $validated['level'] ??= $defaults['defaultLevel'];

        if (($validated['ends_at'] ?? null) === null) {
            $starts = $validated['starts_at'] ?? null;

            $validated['ends_at'] = ($starts === null ? Carbon::now() : Carbon::parse($starts))
                ->addDays($defaults['defaultDurationDays']);
        }

        return $validated;
    }

    private function isUpdate(): bool
    {
        return $this->route('announcement') !== null;
    }
}
