<?php

namespace App\Http\Resources\Catalog;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue product, with its price cells keyed by service type.
 *
 * The grid shape ("cells") is what the point-of-sale screen renders, so it belongs to the
 * product's presentation rather than being assembled ad hoc by whoever asks for it.
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'code' => $this->code,
            'icon' => $this->icon,
            'category_id' => $this->category_id,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,

            'cells' => $this->whenLoaded('services', fn () => $this->cells()),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function cells(): array
    {
        return $this->services
            ->mapWithKeys(fn (Service $service) => [
                $service->service_type->value => [
                    'service_id' => $service->id,
                    'normal' => (float) $service->base_price,
                    'express' => $service->unitPriceFor(true),
                    'is_express_available' => (bool) $service->is_express_available,
                    'pricing_type' => $service->pricing_type->value,
                ],
            ])
            ->all();
    }
}
