<?php

namespace App\Http\Resources\Catalog;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as staff see them, including the wallet balance the counter needs before
 * offering a wallet payment.
 */
class CustomerResource extends JsonResource
{
    /**
     * The subscription the till would draw from, attached only where it was asked for.
     *
     * A setter rather than a constructor argument: `JsonResource::collection` maps
     * items in with `mapInto`, which would pass the item's key as a second parameter
     * and break every listing that uses this resource.
     */
    private ?Subscription $subscription = null;

    private bool $subscriptionUsable = false;

    private bool $subscriptionResolved = false;

    /** @var array<string, mixed>|null */
    private ?array $stats = null;

    /** @var array<string, mixed>|null */
    private ?array $loyalty = null;

    public function withSubscription(?Subscription $subscription, bool $usable): self
    {
        $this->subscription = $subscription;
        $this->subscriptionUsable = $usable;
        $this->subscriptionResolved = true;

        return $this;
    }

    /**
     * The customer-page rollups, same rule as the subscription: only the detail read
     * carries them, a listing leaves the keys out entirely.
     *
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $loyalty
     */
    public function withContext(array $stats, array $loyalty): self
    {
        $this->stats = $stats;
        $this->loyalty = $loyalty;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'birth_date' => $this->birth_date,

            'type' => $this->type,
            'credit_limit' => $this->credit_limit,
            'wallet_balance' => $this->wallet_balance,
            'preferences' => $this->preferences ?? [],

            'branch_id' => $this->branch_id,

            // Present only on the detail read the till makes; a listing leaves the
            // key out entirely rather than reporting a null the client would read
            // as "no subscription".
            'subscription' => $this->when($this->subscriptionResolved, fn () => $this->subscription === null ? null : [
                'id' => $this->subscription->id,
                'status' => $this->subscription->status,
                'plan_name' => $this->subscription->plan?->name,
                'plan_type' => $this->subscription->plan?->type,
                'remaining_quota' => $this->subscription->remaining_quota,
                'remaining_balance' => $this->subscription->remaining_balance,
                'end_at' => $this->subscription->end_at,
                'usable' => $this->subscriptionUsable,
            ]),

            'stats' => $this->when($this->stats !== null, fn () => $this->stats),
            'loyalty' => $this->when($this->loyalty !== null, fn () => $this->loyalty),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
