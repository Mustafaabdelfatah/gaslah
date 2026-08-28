<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a market order, holding the product as it was sold.
 */
class MarketOrderItem extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'unit',
        'unit_price',
        'quantity',
        'line_total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MarketProduct::class, 'product_id');
    }
}
