<?php

namespace App\Http\Requests\Support;

use App\Enum\Support\SupportPriorityEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Validation\Rule;

/**
 * A laundry raising a ticket with the platform.
 */
class OpenSupportTicketRequest extends TenantFormRequest
{
    public function rules(): array
    {
        // The categories are the operator's to configure, so the rule reads them live
        // rather than hard-coding a list that would drift from the settings centre.
        $categories = app(PlatformSettingsService::class)->support()['categories'];

        return [
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'priority' => ['nullable', Rule::in(SupportPriorityEnum::values())],
            'category' => $categories === []
                ? ['nullable', 'string', 'max:60']
                : ['nullable', Rule::in($categories)],
        ];
    }

    public function subject(): string
    {
        return $this->string('subject')->trim()->toString();
    }

    public function body(): string
    {
        return $this->string('body')->trim()->toString();
    }

    /**
     * Normal unless the tenant says otherwise: most tickets are.
     */
    public function priority(): SupportPriorityEnum
    {
        return $this->filled('priority')
            ? SupportPriorityEnum::from($this->string('priority')->toString())
            : SupportPriorityEnum::Normal;
    }

    public function category(): ?string
    {
        return $this->filled('category') ? $this->string('category')->trim()->toString() : null;
    }
}
