<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer as staff see them, including the wallet balance the counter needs before
 * offering a wallet payment.
 */
class CustomerResource extends JsonResource
{
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
