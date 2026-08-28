<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Crm\StoreCrmNoteRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Crm\CrmNoteResource;
use App\Http\Resources\Platform\TenantResource;
use App\Models\CrmNote;
use App\Models\User;
use App\Services\Crm\CrmService;
use Illuminate\Http\JsonResponse;

/**
 * The operator's follow-up desk: which tenants need chasing, and the notes kept against
 * them. Permissions are enforced on the routes.
 */
class AdminCrmController extends BaseController
{
    public function __construct(private readonly CrmService $crm)
    {
        parent::__construct();
    }

    /**
     * The attention list.
     *
     * Unpaginated by design: it is the set of accounts in trouble right now, and a
     * platform with enough of those to need paging has a bigger problem than this screen.
     */
    public function index(): JsonResponse
    {
        $flagged = array_map(
            static fn (array $entry): array => [
                'tenant' => new TenantResource($entry['organization']),
                'reasons' => $entry['reasons'],
            ],
            $this->crm->attentionList(),
        );

        return successResponse(['attention' => $flagged]);
    }

    /**
     * The follow-up log across every lead and tenant.
     */
    public function notes(PageRequest $request): JsonResponse
    {
        $query = CrmNote::query()
            ->with(['lead:id,business_name', 'organization:id,name', 'author:id,name'])
            ->when($request->boolean('pending'), fn ($q) => $q->pending())
            ->latest('id');

        return successResponse(wrapPaginate($query, CrmNoteResource::class));
    }

    public function storeNote(StoreCrmNoteRequest $request): JsonResponse
    {
        $note = $this->crm->addNote($this->admin(), $request->note());

        return successResponse(
            new CrmNoteResource($note->load(['lead:id,business_name', 'organization:id,name', 'author:id,name'])),
            __('api.created_success'),
            201,
        );
    }

    /**
     * Mark a task done. Only a task can be.
     */
    public function completeNote(CrmNote $note): JsonResponse
    {
        $note = $this->crm->complete($note);

        return successResponse(
            new CrmNoteResource($note->load(['lead:id,business_name', 'organization:id,name', 'author:id,name'])),
            __('api.updated_success'),
        );
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
