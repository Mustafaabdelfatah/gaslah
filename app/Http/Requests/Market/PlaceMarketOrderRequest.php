<?php

namespace App\Http\Requests\Market;

use App\Enum\Market\MarketPaymentMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * A laundry placing a market order.
 *
 * The lines are only checked for shape here. Whether each product is still buyable, and
 * whether they all come from one supplier, are questions about the basket as a whole —
 * the service answers those, since it has to hold them true at the moment it writes.
 */
class PlaceMarketOrderRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],

            'payment_method' => ['nullable', Rule::in(MarketPaymentMethodEnum::values())],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, array{product_id: int, quantity: float}>
     */
    public function lines(): array
    {
        return array_map(
            static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (float) $line['quantity'],
            ],
            $this->input('items', []),
        );
    }

    /**
     * Deferred unless the buyer says otherwise: buying on account is the norm between a
     * laundry and its supplier.
     */
    public function paymentMethod(): MarketPaymentMethodEnum
    {
        return $this->filled('payment_method')
            ? MarketPaymentMethodEnum::from($this->string('payment_method')->toString())
            : MarketPaymentMethodEnum::Deferred;
    }

    public function notes(): ?string
    {
        return $this->input('notes');
    }

    public function address(): ?string
    {
        return $this->input('address');
    }
}
