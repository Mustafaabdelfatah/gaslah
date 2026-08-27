<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A piece of hardware the platform sells. The price is VAT-inclusive, as every price the
 * platform quotes is.
 */
class PlatformDevice extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'price',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
