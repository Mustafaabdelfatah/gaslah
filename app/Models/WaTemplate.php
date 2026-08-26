<?php

namespace App\Models;

use App\Enum\Messaging\WaCategoryEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * A message template. A null organization_id is a platform default template.
 */
class WaTemplate extends BaseModel
{
    protected $fillable = [
        'organization_id', 'name', 'category', 'event_key', 'body', 'is_active', 'created_by_id',
    ];

    protected $casts = [
        'category' => WaCategoryEnum::class,
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
