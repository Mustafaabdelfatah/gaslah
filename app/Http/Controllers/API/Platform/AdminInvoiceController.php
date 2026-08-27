<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\OrganizationScopeFilter;
use App\Filters\Platform\StatusFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\DraftInvoiceRequest;
use App\Http\Resources\Platform\SubscriptionInvoiceResource;
use App\Models\Organization;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Platform\SubscriptionInvoicer;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's view of the platform's subscription invoices and the two-step billing
 * flow. The routes carry the split: reading needs view_finance, drafting needs
 * manage_subscriptions, and confirming — recognising revenue — needs manage_accounting.
 */
class AdminInvoiceController extends BaseController
{
    public function __construct(private readonly SubscriptionInvoicer $invoicer)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(SubscriptionInvoice::query()->with('organization:id,name'))
            ->through([OrganizationScopeFilter::class, StatusFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate(
            $query,
            SubscriptionInvoiceResource::class,
            ['totals' => SubscriptionInvoice::recognisedTotals()],
        ));
    }

    public function show(SubscriptionInvoice $invoice): JsonResponse
    {
        return successResponse(
            new SubscriptionInvoiceResource($invoice->load(['organization:id,name', 'confirmedBy:id,name'])),
        );
    }

    public function store(DraftInvoiceRequest $request, Organization $organization): JsonResponse
    {
        $invoice = $this->invoicer->quoteForTenant($organization, $request);

        return successResponse(
            new SubscriptionInvoiceResource($invoice),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }

    public function confirm(SubscriptionInvoice $invoice): JsonResponse
    {
        /** @var User $admin */
        $admin = request()->user();

        return successResponse(
            new SubscriptionInvoiceResource($this->invoicer->confirm($invoice, $admin->getKey())),
            __('api.updated_success'),
        );
    }

    public function destroy(SubscriptionInvoice $invoice): JsonResponse
    {
        abort_unless($invoice->isDraft(), Response::HTTP_CONFLICT, __('api.invoice_issued_no_delete'));

        $invoice->delete();

        return successResponse(null, __('api.deleted_success'));
    }
}
