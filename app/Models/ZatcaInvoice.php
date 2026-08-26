<?php

namespace App\Models;

use App\Enum\Zatca\ZatcaInvoiceStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored ZATCA Phase 2 invoice — one per order, linked into a per-organization hash
 * chain (ICV + PIH).
 */
class ZatcaInvoice extends BaseModel
{
    protected $fillable = [
        'order_id',
        'organization_id',
        'icv',
        'uuid',
        'pih',
        'hash',
        'xml',
        'qr',
        'status',
        'zatca_uuid',
        'reported_at',
    ];

    protected $casts = [
        'status' => ZatcaInvoiceStatusEnum::class,
        'icv' => 'integer',
        'reported_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
