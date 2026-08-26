<?php

namespace App\Http\Controllers\API\Tenancy\Catalog;

use App\Enum\Catalog\ServiceTypeEnum;
use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends TenantController
{
    public function __construct(private readonly CatalogService $catalog)
    {
        parent::__construct();
    }

    /**
     * The active catalog: categories, their products, and each product's price cells
     * keyed by service type. Any staff member may read it.
     */
    public function index(): JsonResponse
    {
        $this->staff();
        $organizationId = $this->organizationId();

        $categories = ServiceCategory::query()
            ->forOrganization($organizationId)
            ->active()
            ->with(['products' => fn ($q) => $q->active()->orderBy('sort_order')->with(['services' => fn ($s) => $s->active()])])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ServiceCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'products' => $category->products
                    ->filter(fn (Product $product) => $product->services->isNotEmpty())
                    ->map(fn (Product $product) => $this->presentProduct($product))
                    ->values(),
            ])
            ->filter(fn ($category) => $category['products']->isNotEmpty())
            ->values();

        return successResponse([
            'categories' => $categories,
            'service_types' => array_map(fn (ServiceTypeEnum $type) => $type->value, ServiceTypeEnum::ordered()),
            'tax_rate' => (float) $this->organization()->tax_rate,
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:255'],
        ]);

        $category = ServiceCategory::query()->create([
            ...$data,
            'organization_id' => $this->organizationId(),
            'sort_order' => (int) ServiceCategory::query()->forOrganization($this->organizationId())->max('sort_order') + 1,
        ]);

        return successResponse($category, __('api.created_success'), 201);
    }

    public function storeProduct(StoreProductRequest $request): JsonResponse
    {
        $this->requireManager();

        $product = $this->catalog->createProduct($this->organizationId(), $request->validated());

        return successResponse($product, __('api.created_success'), 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($product);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return successResponse($this->catalog->renameProduct($product, $data), __('api.updated_success'));
    }

    /**
     * Editing a product code needs its own fine-grained permission.
     */
    public function updateProductCode(Request $request, Product $product): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CatalogManageCodes);
        $this->assertOwned($product);

        $data = $request->validate(['code' => ['nullable', 'string', 'max:100']]);
        $this->assertCodeIsFree($data['code'] ?? null, $product->getKey());

        $product->update($data);

        return successResponse($product->refresh(), __('api.updated_success'));
    }

    /**
     * A price cell exposes only its price and availability — never a delete.
     */
    public function updateService(Request $request, Service $service): JsonResponse
    {
        $this->requireManager();
        abort_unless($service->organization_id === $this->organizationId(), 404, __('api.record_not_found'));

        $data = $request->validate([
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'express_surcharge' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'is_express_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $service->update($data);

        return successResponse($service->refresh(), __('api.updated_success'));
    }

    public function reorderCategories(Request $request): JsonResponse
    {
        $this->requireManager();
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        $this->catalog->reorder(ServiceCategory::class, $this->organizationId(), $ids);

        return successResponse(msg: __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function presentProduct(Product $product): array
    {
        $cells = [];

        foreach ($product->services as $service) {
            $cells[$service->service_type->value] = [
                'service_id' => $service->id,
                'normal' => (float) $service->base_price,
                'express' => $service->unitPriceFor(true),
                'is_express_available' => $service->is_express_available,
                'pricing_type' => $service->pricing_type->value,
            ];
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'cells' => $cells,
        ];
    }

    private function assertOwned(Product $product): void
    {
        abort_unless($product->organization_id === $this->organizationId(), 404, __('api.record_not_found'));
    }

    private function assertCodeIsFree(?string $code, int $ignoreId): void
    {
        if ($code === null || $code === '') {
            return;
        }

        $exists = Product::query()
            ->forOrganization($this->organizationId())
            ->where('code', $code)
            ->whereKeyNot($ignoreId)
            ->exists();

        abort_if($exists, 422, __('validation.unique', ['attribute' => 'code']));
    }
}
