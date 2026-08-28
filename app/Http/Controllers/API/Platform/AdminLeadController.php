<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Crm\LeadStageEnum;
use App\Filters\Crm\LeadFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Crm\ConvertLeadRequest;
use App\Http\Requests\Crm\StoreCrmNoteRequest;
use App\Http\Requests\Crm\StoreLeadRequest;
use App\Http\Requests\Crm\UpdateLeadRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Crm\CrmNoteResource;
use App\Http\Resources\Crm\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The operator's sales pipeline. Permissions are enforced on the routes.
 */
class AdminLeadController extends BaseController
{
    public function __construct(private readonly LeadService $leads)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(Lead::query()->with('owner:id,name')->withCount('notes'))
            ->through([LeadFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, LeadResource::class, [
            'stages' => LeadStageEnum::values(),
            'summary' => $this->leads->summary(),
        ]));
    }

    public function show(Lead $lead): JsonResponse
    {
        return successResponse(new LeadResource($this->withTimeline($lead)));
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $lead = $this->leads->create($request->validated());

        return successResponse(new LeadResource($lead), __('api.created_success'), 201);
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        $lead = $this->leads->update($lead, $request->validated());

        return successResponse(new LeadResource($this->withTimeline($lead)), __('api.updated_success'));
    }

    /**
     * Add an entry to this lead's timeline.
     */
    public function storeNote(StoreCrmNoteRequest $request, Lead $lead): JsonResponse
    {
        $note = $this->leads->addNote($lead, $this->admin(), [
            ...$request->note(),
            // The lead is the one in the path, whatever the body says.
            'lead_id' => $lead->getKey(),
            'organization_id' => null,
        ]);

        return successResponse(new CrmNoteResource($note), __('api.created_success'), 201);
    }

    /**
     * Turn the lead into a real tenant and mark it won.
     */
    public function convert(ConvertLeadRequest $request, Lead $lead): JsonResponse
    {
        $lead = $this->leads->convert($lead, $request->owner());

        return successResponse(new LeadResource($this->withTimeline($lead)), __('api.created_success'), 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * A lead with its timeline, newest entry first — the order a history is read in.
     */
    private function withTimeline(Lead $lead): Lead
    {
        return $lead->load([
            'owner:id,name',
            'notes' => fn ($query) => $query->with('author:id,name')->latest('id'),
        ]);
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
