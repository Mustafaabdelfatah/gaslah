<?php

namespace App\Models;

use App\Enum\Reports\CashMovementTypeEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single cash-drawer movement within a shift.
 */
class CashMovement extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shift_id',
        'type',
        'amount',
        'note',
    ];

    protected $casts = [
        'type' => CashMovementTypeEnum::class,
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
