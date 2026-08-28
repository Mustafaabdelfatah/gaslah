<?php

namespace App\Http\Controllers\API\Tenancy\Support;

use App\Filters\Support\SupportTicketFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Support\OpenSupportTicketRequest;
use App\Http\Requests\Support\ReplyToSupportTicketRequest;
use App\Http\Resources\Support\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The laundry's side of support: raising a ticket with the platform and following it.
 *
 * Every query is scoped to the caller's own organization. A ticket belonging to another
 * laundry answers 404 — it should not be distinguishable from one that does not exist.
 */
class OrgSupportController extends TenantController
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly PlatformSettingsService $settings,
    ) {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(
                SupportTicket::query()
                    ->forOrganization($this->organizationId())
                    ->latest('last_reply_at')
            )
            ->through([SupportTicketFilter::class])
            ->thenReturn();

        // The categories ride along so the "new ticket" form can be built from one
        // response, and always from what the operator has configured.
        return successResponse(wrapPaginate($query, SupportTicketResource::class, [
            'categories' => $this->settings->support()['categories'],
        ]));
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        $this->assertOwned($ticket);

        return successResponse(new SupportTicketResource($this->withThread($ticket)));
    }

    public function store(OpenSupportTicketRequest $request): JsonResponse
    {
        $ticket = $this->tickets->open(
            $this->organization(),
            $this->staff(),
            $request->subject(),
            $request->body(),
            $request->priority(),
            $request->category(),
        );

        return successResponse(
            new SupportTicketResource($this->withThread($ticket)),
            __('api.created_success'),
            201,
        );
    }

    /**
     * Reply into the thread. A settled ticket comes back open: someone still needs help.
     */
    public function reply(ReplyToSupportTicketRequest $request, SupportTicket $ticket): JsonResponse
    {
        $this->assertOwned($ticket);

        $ticket = $this->tickets->replyAsTenant($ticket, $this->staff(), $request->body());

        return successResponse(new SupportTicketResource($this->withThread($ticket)));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * A ticket with its thread, oldest message first — the order a conversation reads in.
     */
    private function withThread(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load(['messages' => fn ($query) => $query->with('author:id,name')->oldest('id')]);
    }
}
