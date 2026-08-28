<?php

namespace App\Http\Resources\Support;

use App\Enum\Support\SupportAuthorTypeEnum;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Request;

/**
 * A ticket as the operator's inbox shows it: the same ticket, plus which laundry it came
 * from, who owns it, and whether it is waiting on us.
 */
class AdminSupportTicketResource extends SupportTicketResource
{
    public function toArray(Request $request): array
    {
        $lastMessage = $this->resource->relationLoaded('lastMessage')
            ? $this->lastMessage->first()
            : null;

        return [
            ...parent::toArray($request),

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),

            'assigned_to_id' => $this->assigned_to_id,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo?->name),

            // Who spoke last decides who is being waited on, so the inbox can be sorted by
            // what actually needs an answer rather than by status alone.
            'last_author_type' => $lastMessage?->author_type,
            'awaiting_us' => $lastMessage?->author_type === SupportAuthorTypeEnum::Tenant
                && ! $this->status->isSettled(),
            'sla_breached' => $this->tickets()->isSlaBreached($this->resource, $lastMessage),
        ];
    }

    /**
     * Resolved through the container rather than injected: a resource is constructed per
     * row, so there is nowhere to hand it in. The service and the settings behind it are
     * singletons, so a page of tickets still reads the SLA policy once.
     */
    private function tickets(): SupportTicketService
    {
        return app(SupportTicketService::class);
    }
}
