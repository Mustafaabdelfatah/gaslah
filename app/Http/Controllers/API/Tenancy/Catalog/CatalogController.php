<?php

namespace App\Http\Controllers\API\Tenancy\Catalog;

use App\Enum\Catalog\ServiceTypeEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Catalog\ReorderCategoriesRequest;
use App\Http\Requests\Catalog\ReorderProductsRequest;
use App\Http\Requests\Catalog\ServiceCategoryRequest;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductCodeRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Http\Requests\Catalog\UpdateServiceRequest;
use App\Http\Resources\Catalog\ProductResource;
use App\Http\Resources\Catalog\ServiceCategoryResource;
use App\Http\Resources\Catalog\ServiceResource;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;

class CatalogController extends TenantController
{
    public function __construct(private readonly CatalogService $catalog)
    {
        parent::__construct();
    }

    /**
     * The sellable catalogue — categories, their products, and each product's price cells
     * keyed by service type. Any staff member may read it.
     */
    public function index(): JsonResponse
    {
        $this->staff();

        return successResponse([
            'categories' => ServiceCategoryResource::collection($this->catalog->sellableTree($this->organizationId())),
            'all_categories' => ServiceCategoryResource::collection($this->catalog->activeCategories($this->organizationId())),
            'service_types' => array_map(fn (ServiceTypeEnum $type) => $type->value, ServiceTypeEnum::ordered()),
            'tax_rate' => (float) $this->organization()->tax_rate,
        ]);
    }

    public function storeCategory(ServiceCategoryRequest $request): JsonResponse
    {

        $category = $this->catalog->createCategory($this->organizationId(), $request->validated());

        return successResponse(new ServiceCategoryResource($category), __('api.created_success'), 201);
    }

    public function storeProduct(StoreProductRequest $request): JsonResponse
    {

        $product = $this->catalog->createProduct($this->organizationId(), $request->validated());

        return successResponse(new ProductResource($product), __('api.created_success'), 201);
    }

    public function updateProduct(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->assertOwned($product);

        $product = $this->catalog->renameProduct($product, $request->validated());

        return successResponse(new ProductResource($product), __('api.updated_success'));
    }

    /**
     * Editing a product code needs its own fine-grained permission.
     */
    public function updateProductCode(UpdateProductCodeRequest $request, Product $product): JsonResponse
    {
        $this->assertOwned($product);

        $product->update($request->validated());

        return successResponse(new ProductResource($product->refresh()), __('api.updated_success'));
    }

    /**
     * A price cell exposes only its price and availability — never a delete.
     */
    public function updateService(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $this->assertOwned($service);

        $service->update($request->validated());

        return successResponse(new ServiceResource($service->refresh()), __('api.updated_success'));
    }

    public function reorderCategories(ReorderCategoriesRequest $request): JsonResponse
    {

        $this->catalog->reorder(ServiceCategory::class, $this->organizationId(), $request->ids());

        return successResponse(msg: __('api.updated_success'));
    }

    public function reorderProducts(ReorderProductsRequest $request): JsonResponse
    {

        $this->catalog->reorder(Product::class, $this->organizationId(), $request->ids());

        return successResponse(msg: __('api.updated_success'));
    }
}
