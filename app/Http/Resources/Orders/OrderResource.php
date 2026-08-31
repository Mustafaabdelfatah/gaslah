<?php

namespace App\Http\Resources\Orders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as staff see it.
 *
 * Money is reported as stored plus the one figure nobody should be recomputing on the
 * client — what is still owed. Items, payments and history appear only when the caller
 * asked for the detail view.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'barcode' => $this->barcode,

            'status' => $this->status,
            // The workflow machine is the server's; a client that encoded it would
            // drift the moment the rules change.
            'next_statuses' => array_map(
                static fn ($status) => $status->value,
                $this->status->allowedNext(),
            ),
            'priority' => $this->priority,
            'payment_status' => $this->payment_status,

            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'cashier_id' => $this->cashier_id,
            'subscription_id' => $this->subscription_id,

            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_rate' => $this->tax_rate,
            'tax_total' => $this->tax_total,
            'delivery_fee' => $this->delivery_fee,
            'grand_total' => $this->grand_total,
            'paid_total' => $this->paid_total,
            'remaining' => $this->remaining(),

            'due_at' => $this->due_at,
            'delivered_at' => $this->delivered_at,
            'notes' => $this->notes,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'status_histories' => $this->whenLoaded('statusHistories'),

            'created_at' => $this->created_at,
        ];
    }
}
