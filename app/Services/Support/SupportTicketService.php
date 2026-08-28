<?php

namespace App\Services\Support;

use App\Enum\Support\SupportAuthorTypeEnum;
use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets, from either side of the conversation.
 *
 * Both sides post into the same thread, so both go through here: a reply has to write the
 * message, move the status and stamp the activity together, and doing that in two places
 * is how the two sides end up disagreeing about where a ticket stands.
 */
class SupportTicketService
{
    public function __construct(private readonly PlatformSettingsService $settings) {}

    /**
     * Open a ticket with its first message.
     */
    public function open(
        Organization $organization,
        User $author,
        string $subject,
        string $body,
        SupportPriorityEnum $priority,
        ?string $category = null,
    ): SupportTicket {
        $support = $this->settings->support();

        return DB::transaction(function () use ($organization, $author, $subject, $body, $priority, $category, $support) {
            $now = Carbon::now();

            $ticket = SupportTicket::query()->create([
                'organization_id' => $organization->getKey(),
                'subject' => $subject,
                'category' => $category,
                'status' => SupportTicketStatusEnum::Open->value,
                'priority' => $priority->value,
                'created_by_id' => $author->getKey(),
                'last_reply_at' => $now,
            ]);

            $this->post($ticket, SupportAuthorTypeEnum::Tenant, $author->getKey(), $body);

            // An acknowledgement, when the operator has one configured. It has no author:
            // nobody wrote it, and attributing it to an admin would be a lie in the thread.
            $autoReply = trim((string) $support['autoReplyText']);

            if ($support['autoReplyEnabled'] && $autoReply !== '') {
                $this->post($ticket, SupportAuthorTypeEnum::Admin, null, $autoReply);
            }

            return $ticket->refresh();
        });
    }

    /**
     * The tenant replies.
     *
     * A settled ticket comes back open: someone still needs help, whatever the operator
     * had marked it.
     */
    public function replyAsTenant(SupportTicket $ticket, User $author, string $body): SupportTicket
    {
        return $this->reply(
            $ticket,
            SupportAuthorTypeEnum::Tenant,
            $author,
            $body,
            $ticket->status->isSettled() ? SupportTicketStatusEnum::Open : $ticket->status,
        );
    }

    /**
     * The operator replies, which puts the ticket on the tenant.
     *
     * A closed ticket stays closed — an operator adding a closing note should not drag it
     * back into the queue. Anything still live becomes pending.
     */
    public function replyAsAdmin(SupportTicket $ticket, User $admin, string $body): SupportTicket
    {
        return $this->reply(
            $ticket,
            SupportAuthorTypeEnum::Admin,
            $admin,
            $body,
            $ticket->status->isSettled() ? $ticket->status : SupportTicketStatusEnum::Pending,
        );
    }

    /**
     * Change where a ticket stands, who owns it, or how urgent it is.
     *
     * Assignment is deliberately nullable: handing a ticket back to the queue is a real
     * action, so an explicit null unassigns rather than being read as "unchanged".
     *
     * @param  array{status?: SupportTicketStatusEnum, priority?: SupportPriorityEnum, assigned_to_id?: int|null}  $changes
     */
    public function update(SupportTicket $ticket, array $changes): SupportTicket
    {
        $attributes = [];

        if (isset($changes['status'])) {
            $attributes['status'] = $changes['status']->value;
        }

        if (isset($changes['priority'])) {
            $attributes['priority'] = $changes['priority']->value;
        }

        if (array_key_exists('assigned_to_id', $changes)) {
            $attributes['assigned_to_id'] = $changes['assigned_to_id'];
        }

        if ($attributes !== []) {
            $ticket->forceFill($attributes)->save();
        }

        return $ticket->refresh();
    }

    /**
     * Whether the operator has left this ticket unanswered longer than it promised to.
     *
     * Read from the thread rather than stored: the promise itself is a setting the
     * operator can change, and a stored flag would freeze yesterday's answer.
     */
    public function isSlaBreached(SupportTicket $ticket, ?SupportTicketMessage $lastMessage): bool
    {
        // Only a ticket waiting on us can breach. One waiting on the tenant, or settled,
        // is not ours to answer.
        if ($lastMessage?->author_type !== SupportAuthorTypeEnum::Tenant || $ticket->status->isSettled()) {
            return false;
        }

        $minutes = $this->settings->support()['slaResponseMinutes'];

        return $lastMessage->created_at?->addMinutes($minutes)->isPast() ?? false;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function reply(
        SupportTicket $ticket,
        SupportAuthorTypeEnum $authorType,
        User $author,
        string $body,
        SupportTicketStatusEnum $status,
    ): SupportTicket {
        return DB::transaction(function () use ($ticket, $authorType, $author, $body, $status) {
            $this->post($ticket, $authorType, $author->getKey(), $body);

            $ticket->forceFill([
                'status' => $status->value,
                'last_reply_at' => Carbon::now(),
            ])->save();

            return $ticket->refresh();
        });
    }

    private function post(SupportTicket $ticket, SupportAuthorTypeEnum $authorType, ?int $authorId, string $body): void
    {
        $ticket->messages()->create([
            'author_type' => $authorType->value,
            'author_id' => $authorId,
            'body' => $body,
        ]);
    }
}
