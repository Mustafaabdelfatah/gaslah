<?php

namespace App\Http\Resources\Loyalty;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's points balance. The customer's name and phone are flattened in because the
 * members table is the only place this list is read, and it always shows both.
 */
class LoyaltyAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),

            'points_balance' => $this->points_balance,
            'lifetime_points' => $this->lifetime_points,
        ];
    }
}
