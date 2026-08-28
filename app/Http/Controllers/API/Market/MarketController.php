<?php

namespace App\Http\Controllers\API\Market;

use App\Enum\Market\MarketCategoryEnum;
use App\Filters\Global\OrderByFilter;
use App\Filters\Market\MarketOrderFilter;
use App\Filters\Market\MarketProductFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Market\PlaceMarketOrderRequest;
use App\Http\Resources\Market\MarketOrderResource;
use App\Http\Resources\Market\MarketProductResource;
use App\Models\MarketOrder;
use App\Models\MarketProduct;
use App\Services\Market\MarketOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The buyer's side of the supplies market: a laundry browsing the catalogue and ordering
 * from it.
 *
 * Read and create only. There is deliberately no route here for changing an order's state
 * or cancelling it — the lifecycle belongs to the supplier, who is the one who has to
 * confirm, ship and deliver it.
 */
class MarketController extends TenantController
{
    public function __construct(private readonly MarketOrderService $orders)
    {
        parent::__construct();
    }

    /**
     * Browse the market. Only listed products from approved suppliers are visible.
     */
    public function products(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(MarketProduct::query()->buyable()->with('supplier:id,name,city'))
            ->through([MarketProductFilter::class, OrderByFilter::class])
            ->thenReturn();

        // The category list rides along so the browse screen can render its filter bar
        // from one response.
        return successResponse(wrapPaginate($query, MarketProductResource::class, [
            'categories' => MarketCategoryEnum::catalogue(),
        ]));
    }

    public function product(MarketProduct $product): JsonResponse
    {
        // Resolved by id alone, so it still has to be proven buyable — a delisted product,
        // or one whose supplier is suspended, is not in the market at all.
        abort_unless(
            MarketProduct::query()->buyable()->whereKey($product->getKey())->exists(),
            404,
            __('api.record_not_found'),
        );

        return successResponse(new MarketProductResource($product->load('supplier:id,name,city')));
    }

    /**
     * Place an order with a supplier.
     */
    public function placeOrder(PlaceMarketOrderRequest $request): JsonResponse
    {
        $order = $this->orders->place(
            $request->lines(),
            [
                'organization_id' => $this->organizationId(),
                'branch_id' => $this->writeBranchId(),
                'created_by_id' => $this->staff()->getKey(),
            ],
            $request->paymentMethod(),
            $request->notes(),
            $request->address(),
        );

        return successResponse(new MarketOrderResource($order), __('api.created_success'), 201);
    }

    /**
     * The orders this laundry has placed.
     */
    public function orders(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(
                MarketOrder::query()
                    ->forOrganization($this->organizationId())
                    ->with(['items', 'supplier:id,name,phone,city'])
                    ->latest('id')
            )
            ->through([MarketOrderFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, MarketOrderResource::class));
    }

    public function order(MarketOrder $marketOrder): JsonResponse
    {
        $this->assertOwned($marketOrder);

        return successResponse(new MarketOrderResource(
            $marketOrder->load('items', 'supplier:id,name,phone,city')
        ));
    }
}
