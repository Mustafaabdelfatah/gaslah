<?php

namespace App\Models;

use App\Enum\Catalog\PricingTypeEnum;
use App\Enum\Catalog\ServiceTypeEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single price cell: one product priced for one service type.
 *
 * Order items reference a service, so it is never deleted — only deactivated.
 */
class Service extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'category_id',
        'product_id',
        'service_type',
        'name',
        'pricing_type',
        'base_price',
        'express_surcharge',
        'is_express_available',
        'is_active',
    ];

    protected $casts = [
        'service_type' => ServiceTypeEnum::class,
        'pricing_type' => PricingTypeEnum::class,
        'base_price' => 'decimal:2',
        'express_surcharge' => 'decimal:2',
        'is_express_available' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * The authoritative server-side unit price for a line: base plus the express
     * surcharge only when express is both requested and available. A client's price
     * is never trusted.
     */
    public function unitPriceFor(bool $express): float
    {
        $surcharge = $express && $this->is_express_available ? (float) $this->express_surcharge : 0.0;

        return round((float) $this->base_price + $surcharge, 2);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
