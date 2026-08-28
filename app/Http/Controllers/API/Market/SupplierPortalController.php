<?php

namespace App\Http\Controllers\API\Market;

use App\Enum\Market\MarketCategoryEnum;
use App\Filters\Global\OrderByFilter;
use App\Filters\Market\MarketOrderFilter;
use App\Filters\Market\MarketProductFilter;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Market\MarketProductRequest;
use App\Http\Requests\Market\UpdateMarketOrderStatusRequest;
use App\Http\Resources\Market\MarketProductResource;
use App\Http\Resources\Market\MarketSupplierResource;
use App\Http\Resources\Market\SupplierMarketOrderResource;
use App\Models\MarketOrder;
use App\Models\MarketProduct;
use App\Services\Market\MarketOrderService;
use App\Services\Market\SupplierStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The supplier's portal: their profile and figures, their catalogue, and the orders placed
 * with them — which only they can move along.
 *
 * Everything here is scoped to the signed-in supplier's id, so one supplier can never
 * reach another's rows.
 */
class SupplierPortalController extends SupplierBaseController
{
    public function __construct(
        private readonly MarketOrderService $orders,
        private readonly SupplierStatsService $stats,
    ) {
        parent::__construct();
    }

    /**
     * The supplier's own profile, with the headline figures for their portal.
     */
    public function me(): JsonResponse
    {
        $supplier = $this->supplier();

        return successResponse([
            'supplier' => new MarketSupplierResource($supplier),
            'stats' => $this->stats->summary($supplier),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    /**
     * The supplier's whole catalogue, delisted products included — unlike the buyer's
     * view, which shows only what is on sale.
     */
    public function products(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(MarketProduct::query()->where('supplier_id', $this->supplier()->getKey()))
            ->through([MarketProductFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, MarketProductResource::class, [
            'categories' => MarketCategoryEnum::catalogue(),
        ]));
    }

    public function storeProduct(MarketProductRequest $request): JsonResponse
    {
        $product = MarketProduct::query()->create([
            ...$request->validated(),
            'supplier_id' => $this->supplier()->getKey(),
        ]);

        return successResponse(new MarketProductResource($product->refresh()), __('api.created_success'), 201);
    }

    public function updateProduct(MarketProductRequest $request, MarketProduct $product): JsonResponse
    {
        $this->assertOwnedProduct($product);

        $product->update($request->validated());

        return successResponse(new MarketProductResource($product->refresh()), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    /**
     * The orders placed with this supplier.
     */
    public function orders(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(
                MarketOrder::query()
                    ->forSupplier($this->supplier()->getKey())
                    ->with(['items', 'organization:id,name'])
                    ->latest('id')
            )
            ->through([MarketOrderFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, SupplierMarketOrderResource::class));
    }

    public function order(MarketOrder $marketOrder): JsonResponse
    {
        $this->assertOwnedOrder($marketOrder);

        return successResponse(new SupplierMarketOrderResource(
            $marketOrder->load('items', 'organization:id,name')
        ));
    }

    /**
     * Move an order along: confirm it, ship it, deliver it, or cancel it.
     */
    public function updateOrderStatus(UpdateMarketOrderStatusRequest $request, MarketOrder $marketOrder): JsonResponse
    {
        $this->assertOwnedOrder($marketOrder);

        $order = $this->orders->transition($marketOrder, $request->status());

        return successResponse(
            new SupplierMarketOrderResource($order->load('items', 'organization:id,name')),
            __('api.status_updated_success'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    |
    | Route-model binding resolves by id alone, so anything reached that way has to be
    | proven to be this supplier's. Both answer 404: another supplier's row should not be
    | distinguishable from one that does not exist.
    */

    private function assertOwnedProduct(MarketProduct $product): void
    {
        abort_unless(
            $product->supplier_id === $this->supplier()->getKey(),
            404,
            __('api.record_not_found'),
        );
    }

    private function assertOwnedOrder(MarketOrder $order): void
    {
        abort_unless(
            $order->supplier_id === $this->supplier()->getKey(),
            404,
            __('api.record_not_found'),
        );
    }
}
