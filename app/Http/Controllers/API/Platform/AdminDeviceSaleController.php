<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\OrganizationScopeFilter;
use App\Filters\Platform\StatusFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\DraftDeviceSaleRequest;
use App\Http\Resources\Platform\DeviceSaleResource;
use App\Models\DeviceSale;
use App\Models\User;
use App\Services\Platform\DeviceInvoicer;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Device-sale invoices, on the same two-step flow as subscriptions and split the same way
 * across permissions: view_finance reads, manage_subscriptions drafts and deletes,
 * manage_accounting confirms — because confirming is what recognises the revenue.
 */
class AdminDeviceSaleController extends BaseController
{
    public function __construct(private readonly DeviceInvoicer $invoicer)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(DeviceSale::query()->with('organization:id,name'))
            ->through([OrganizationScopeFilter::class, StatusFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate(
            $query,
            DeviceSaleResource::class,
            ['totals' => $this->recognisedTotals()],
        ));
    }

    public function show(DeviceSale $sale): JsonResponse
    {
        return successResponse(
            new DeviceSaleResource($sale->load(['organization:id,name', 'confirmedBy:id,name'])),
        );
    }

    public function store(DraftDeviceSaleRequest $request): JsonResponse
    {
        $sale = $this->invoicer->quote(
            $request->organization(),
            $request->buyerName(),
            $request->lines(),
            $request->paymentMethod(),
            $request->paymentMeta(),
            $request->input('buyer_vat'),
            $request->input('notes'),
        );

        return successResponse(new DeviceSaleResource($sale), __('api.created_success'), Response::HTTP_CREATED);
    }

    public function confirm(DeviceSale $sale): JsonResponse
    {
        /** @var User $admin */
        $admin = request()->user();

        return successResponse(
            new DeviceSaleResource($this->invoicer->confirm($sale, $admin->getKey())),
            __('api.updated_success'),
        );
    }

    public function destroy(DeviceSale $sale): JsonResponse
    {
        abort_unless($sale->isDraft(), Response::HTTP_CONFLICT, __('api.invoice_issued_no_delete'));

        $sale->delete();

        return successResponse(null, __('api.deleted_success'));
    }

    /**
     * Issued sales only — a draft is not revenue.
     *
     * @return array{issued_count: int, revenue: float, vat: float, total: float}
     */
    private function recognisedTotals(): array
    {
        $totals = DeviceSale::query()
            ->issued()
            ->selectRaw('COUNT(*) as issued_count')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM(vat), 0) as vat')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'issued_count' => (int) $totals->issued_count,
            'revenue' => round((float) $totals->revenue, 2),
            'vat' => round((float) $totals->vat, 2),
            'total' => round((float) $totals->total, 2),
        ];
    }
}
