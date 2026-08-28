<?php

namespace App\Http\Resources\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market order.
 *
 * The commission split is shown only to the side entitled to it: a supplier needs to see
 * what they will be paid, while a buyer sees the price they pay and nothing about what the
 * platform takes — that is between the platform and the supplier.
 */
class MarketOrderResource extends JsonResource
{
    /**
     * Whether the reader is the selling supplier. Flipped by the supplier-side subclass
     * rather than passed in, so the resource still composes with wrapPaginate.
     */
    protected function readerIsSupplier(): bool
    {
        return false;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,

            'subtotal' => $this->subtotal,
            'total' => $this->total,

            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'phone' => $this->supplier->phone,
                'city' => $this->supplier->city,
            ]),

            'organization_id' => $this->organization_id,
            'buyer' => $this->whenLoaded('organization', fn () => $this->organization?->name),

            'address' => $this->address,
            'notes' => $this->notes,

            'items' => MarketOrderItemResource::collection($this->whenLoaded('items')),

            // What the platform keeps, and what is left for the supplier. Withheld from
            // the buyer, whose bill is the subtotal either way.
            'commission_type' => $this->when($this->readerIsSupplier(), fn () => $this->commission_type),
            'commission_rate' => $this->when($this->readerIsSupplier(), fn () => $this->commission_rate),
            'commission_amount' => $this->when($this->readerIsSupplier(), fn () => $this->commission_amount),
            'supplier_payout' => $this->when($this->readerIsSupplier(), fn () => $this->supplier_payout),

            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
