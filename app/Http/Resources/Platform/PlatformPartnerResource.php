<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A founding partner, with the money figures the overview attaches.
 *
 * `effective_ownership` is what actually counts toward the ceiling and the profit split —
 * an inactive partner keeps their recorded percentage but contributes zero.
 */
class PlatformPartnerResource extends JsonResource
{
    /**
     * @param  array{share: float, distributed: float, net_owed: float}|null  $money
     */
    public function __construct($resource, private readonly ?array $money = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'email' => $this->email,

            'ownership_percent' => $this->ownership_percent,
            'effective_ownership' => $this->effectiveOwnership(),

            'joined_at' => $this->joined_at,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,

            'share' => $this->when($this->money !== null, fn () => $this->money['share']),
            'distributed' => $this->when($this->money !== null, fn () => $this->money['distributed']),
            'net_owed' => $this->when($this->money !== null, fn () => $this->money['net_owed']),

            'created_at' => $this->created_at,
        ];
    }
}
