<?php

namespace App\Models;

/**
 * Platform-wide payout settings — a single row.
 */
class PayoutSetting extends BaseModel
{
    protected $fillable = [
        'fee_fixed',
        'fee_percent',
        'min_amount',
        'required_approvals',
        'days',
    ];

    protected $casts = [
        'fee_fixed' => 'decimal:2',
        'fee_percent' => 'decimal:3',
        'min_amount' => 'decimal:2',
        'required_approvals' => 'integer',
        'days' => 'array',
    ];
}
