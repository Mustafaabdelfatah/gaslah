<?php

namespace App\Services\Portal;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * A customer's saved pickup and delivery addresses.
 */
class CustomerAddressService
{
    /**
     * Save an address, keeping exactly one default.
     *
     * Clearing the old default and setting the new one are one transaction: done
     * separately, a failure between them leaves the customer with two defaults or none,
     * and the next delivery goes to whichever the query happens to return first.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(Customer $customer, array $data, bool $makeDefault): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data, $makeDefault) {
            if ($makeDefault) {
                CustomerAddress::query()
                    ->where('customer_id', $customer->getKey())
                    ->update(['is_default' => false]);
            }

            return CustomerAddress::query()->create([
                'customer_id' => $customer->getKey(),
                'label' => $data['label'],
                'district' => $data['district'] ?? null,
                'street' => $data['street'] ?? null,
                'details' => $data['details'] ?? null,
                'is_default' => $makeDefault,
            ]);
        });
    }
}
