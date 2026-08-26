<?php

namespace App\Models;

use App\Enum\Platform\PlatformEventTypeEnum;

/**
 * A subscription lifecycle event for the MRR waterfall.
 */
class PlatformEvent extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'type', 'plan_name', 'cycle', 'monthly', 'amount'];

    protected $casts = [
        'type' => PlatformEventTypeEnum::class,
        'monthly' => 'decimal:2',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];
}
