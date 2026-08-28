<?php

namespace App\Models;

use App\Enum\Market\MarketCategoryEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product a market supplier lists. Stock of null means unlimited.
 */
class MarketProduct extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'supplier_id',
        'name',
        'name_en',
        'category',
        'description',
        'unit',
        'price',
        'stock',
        'image_url',
        'is_active',
    ];

    protected $casts = [
        'category' => MarketCategoryEnum::class,
        'price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Products a buyer may actually see and order: listed by the supplier, and from a
     * supplier the platform has approved. Both halves matter — an approved supplier can
     * still delist a product, and a delisted supplier's active products must vanish.
     */
    public function scopeBuyable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('supplier', fn (Builder $supplier) => $supplier->sellable());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MarketSupplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketOrderItem::class, 'product_id');
    }
}
