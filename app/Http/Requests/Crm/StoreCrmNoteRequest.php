<?php

namespace App\Http\Requests\Crm;

use App\Enum\Crm\CrmNoteKindEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A follow-up entry against a lead or a tenant.
 *
 * Exactly one subject, matching the database's own constraint: a note attached to neither
 * belongs nowhere, and one attached to both would appear on two timelines.
 */
class StoreCrmNoteRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'lead_id' => ['required_without:organization_id', 'nullable', 'integer', 'prohibits:organization_id', Rule::exists('leads', 'id')],
            'organization_id' => ['required_without:lead_id', 'nullable', 'integer', Rule::exists('organizations', 'id')],

            'kind' => ['nullable', Rule::in(CrmNoteKindEnum::values())],
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'due_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function note(): array
    {
        return [
            'lead_id' => $this->input('lead_id'),
            'organization_id' => $this->input('organization_id'),
            'kind' => $this->filled('kind') ? $this->string('kind')->toString() : CrmNoteKindEnum::Note->value,
            'body' => $this->string('body')->trim()->toString(),
            'due_at' => $this->input('due_at'),
        ];
    }
}
