<?php

namespace App\Http\Requests\Support;

use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * The operator triaging a ticket: where it stands, how urgent it is, and who owns it.
 */
class UpdateSupportTicketRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(SupportTicketStatusEnum::values())],
            'priority' => ['nullable', Rule::in(SupportPriorityEnum::values())],

            // Only a platform admin can own a ticket. Scoped in the rule rather than
            // checked afterwards, so assigning to a tenant's staff member is a 422 with a
            // field name on it, not a silent success.
            'assigned_to_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('is_platform_owner', true),
            ],
        ];
    }

    /**
     * Only what was actually sent, so an omitted field stays as it is.
     *
     * Assignment is the exception: an explicit null is how a ticket is handed back to the
     * queue, so its presence is what counts, not its value.
     *
     * @return array{status?: SupportTicketStatusEnum, priority?: SupportPriorityEnum, assigned_to_id?: int|null}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->filled('status')) {
            $changes['status'] = SupportTicketStatusEnum::from($this->string('status')->toString());
        }

        if ($this->filled('priority')) {
            $changes['priority'] = SupportPriorityEnum::from($this->string('priority')->toString());
        }

        if ($this->has('assigned_to_id')) {
            $assignee = $this->input('assigned_to_id');
            $changes['assigned_to_id'] = $assignee === null ? null : (int) $assignee;
        }

        return $changes;
    }
}
