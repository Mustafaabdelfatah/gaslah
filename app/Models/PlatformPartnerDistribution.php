<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash paid out to a partner against their share.
 */
class PlatformPartnerDistribution extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'partner_id',
        'amount',
        'date',
        'note',
        'recorded_by_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'created_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PlatformPartner::class, 'partner_id');
    }
}
