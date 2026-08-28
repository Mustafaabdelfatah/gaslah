<?php

namespace App\Http\Resources\Market;

/**
 * A market order as its selling supplier sees it — the same shape, plus the commission
 * split and the payout, which the buyer's view withholds.
 */
class SupplierMarketOrderResource extends MarketOrderResource
{
    protected function readerIsSupplier(): bool
    {
        return true;
    }
}
