<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Support\SupportTicketStatusEnum;
use App\Filters\Support\SupportTicketFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Support\ReplyToSupportTicketRequest;
use App\Http\Requests\Support\UpdateSupportTicketRequest;
use App\Http\Resources\Support\AdminSupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The operator's support inbox, across every tenant.
 *
 * Any platform admin may read it; replying and triaging need `manage_support`. Enforced on
 * the routes.
 */
class AdminSupportController extends BaseController
{
    public function __construct(private readonly SupportTicketService $tickets)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send($this->inboxQuery())
            ->through([SupportTicketFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, AdminSupportTicketResource::class, [
            'counts' => $this->countsByStatus(),
        ]));
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        return successResponse(new AdminSupportTicketResource($this->withThread($ticket)));
    }

    /**
     * Answer the tenant, which puts the ticket back on them.
     */
    public function reply(ReplyToSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $ticket = $this->tickets->replyAsAdmin($ticket, $this->admin(), $request->body());

        return successResponse(new AdminSupportTicketResource($this->withThread($ticket)));
    }

    /**
     * Triage: where it stands, how urgent it is, and who owns it.
     */
    public function update(UpdateSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $ticket = $this->tickets->update($ticket, $request->changes());

        return successResponse(
            new AdminSupportTicketResource($this->withThread($ticket)),
            __('api.updated_success'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The inbox listing. `lastMessage` is eager-loaded because the resource reads it for
     * every row to work out who is being waited on.
     */
    private function inboxQuery()
    {
        return SupportTicket::query()
            ->with(['organization:id,name', 'assignedTo:id,name', 'lastMessage'])
            ->latest('last_reply_at');
    }

    private function withThread(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load([
            'organization:id,name',
            'assignedTo:id,name',
            'lastMessage',
            'messages' => fn ($query) => $query->with('author:id,name')->oldest('id'),
        ]);
    }

    /**
     * How many tickets sit in each status, every status present even at zero so the
     * inbox's tabs do not appear and disappear as the queue moves.
     *
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        $counts = SupportTicket::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];

        foreach (SupportTicketStatusEnum::cases() as $status) {
            $byStatus[$status->value] = (int) $counts->get($status->value, 0);
        }

        return ['total' => array_sum($byStatus), ...$byStatus];
    }

    /**
     * The acting platform admin. The route middleware has already proven the session, so
     * this only narrows the type for the service call.
     */
    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
