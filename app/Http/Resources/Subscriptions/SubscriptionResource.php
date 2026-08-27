<?php

namespace App\Http\Resources\Subscriptions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer's package.
 *
 * Which of the two remaining figures matters depends on the plan type — a quota package
 * counts pieces, a prepaid one counts money — so both are reported and the plan says how
 * to read them.
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,

            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),

            'plan_id' => $this->plan_id,
            'plan' => new SubscriptionPlanResource($this->whenLoaded('plan')),

            'remaining_quota' => $this->remaining_quota,
            'remaining_balance' => $this->remaining_balance,

            'branch_id' => $this->branch_id,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'auto_renew' => (bool) $this->auto_renew,

            'created_at' => $this->created_at,
        ];
    }
}
