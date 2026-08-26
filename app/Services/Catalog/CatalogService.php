<?php

namespace App\Services\Catalog;

use App\Enum\Catalog\PricingTypeEnum;
use App\Enum\Catalog\ServiceTypeEnum;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catalog write operations.
 *
 * A product and its price cells are created together in one transaction, and a
 * product rename fans out to keep every cell's name in step. Nothing is ever deleted
 * — order items reference services — only deactivated.
 */
class CatalogService
{
    /**
     * Create a product with one price cell per requested service type.
     *
     * @param  array{name: string, category_id: int, name_en?: string, code?: string, icon?: string, cells: array<string, array{base_price?: float, express_surcharge?: float, is_express_available?: bool}>}  $data
     */
    public function createProduct(int $organizationId, array $data): Product
    {
        $cells = $this->validCells($data['cells'] ?? []);

        if ($cells === []) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.service_type_required'));
        }

        $category = ServiceCategory::query()->forOrganization($organizationId)->findOrFail($data['category_id']);

        return DB::transaction(function () use ($organizationId, $data, $category, $cells) {
            $product = Product::query()->create([
                'organization_id' => $organizationId,
                'category_id' => $category->getKey(),
                'name' => $data['name'],
                'name_en' => $data['name_en'] ?? null,
                'icon' => $data['icon'] ?? null,
                'code' => $data['code'] ?? null,
                'sort_order' => $this->nextSortOrder(Product::class, $organizationId),
                'is_active' => true,
            ]);

            foreach ($cells as $type => $cell) {
                Service::query()->create([
                    'organization_id' => $organizationId,
                    'category_id' => $category->getKey(),
                    'product_id' => $product->getKey(),
                    'service_type' => $type,
                    'name' => $product->name,
                    'pricing_type' => PricingTypeEnum::PerPiece->value,
                    'base_price' => round((float) ($cell['base_price'] ?? 0), 2),
                    'express_surcharge' => round((float) ($cell['express_surcharge'] ?? 0), 2),
                    'is_express_available' => (bool) ($cell['is_express_available'] ?? false),
                    'is_active' => true,
                ]);
            }

            return $product->load('services');
        });
    }

    /**
     * Rename a product and sync the name onto every one of its cells.
     */
    public function renameProduct(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update($data);

            if (array_key_exists('name', $data)) {
                Service::query()->where('product_id', $product->getKey())->update(['name' => $product->name]);
            }

            return $product->refresh();
        });
    }

    /**
     * Reorder rows by their position in the id list. Any foreign id fails the whole
     * request.
     *
     * @param  class-string  $model
     * @param  array<int, int>  $ids
     */
    public function reorder(string $model, int $organizationId, array $ids): void
    {
        $owned = $model::query()->where('organization_id', $organizationId)->whereIn('id', $ids)->count();

        abort_unless($owned === count($ids), 404, __('api.record_not_found'));

        DB::transaction(function () use ($model, $ids) {
            foreach ($ids as $position => $id) {
                $model::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Keep only the recognised service types; anything else is dropped.
     *
     * @param  array<string, array<string, mixed>>  $cells
     * @return array<string, array<string, mixed>>
     */
    private function validCells(array $cells): array
    {
        return array_intersect_key($cells, array_flip(ServiceTypeEnum::values()));
    }

    private function nextSortOrder(string $model, int $organizationId): int
    {
        return (int) $model::query()->where('organization_id', $organizationId)->max('sort_order') + 1;
    }
}
