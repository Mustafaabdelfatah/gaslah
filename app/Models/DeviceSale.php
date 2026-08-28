<?php

namespace App\Models;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ZATCA tax invoice for hardware the platform sold, on its own DEV- chain.
 *
 * The buyer may be a tenant or an outside party, so `organization_id` is optional and the
 * buyer's name always stands on its own.
 */
class DeviceSale extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'invoice_no',
        'buyer_name',
        'buyer_vat',
        'seller_name',
        'seller_vat',
        'items',
        'notes',
        'subtotal',
        'vat',
        'total',
        'payment_method',
        'bank_name',
        'transfer_ref',
        'gateway_name',
        'icv',
        'pih',
        'hash',
        'qr',
        'status',
        'confirmed_at',
        'confirmed_by_id',
        'issued_at',
    ];

    protected $casts = [
        'status' => SubscriptionInvoiceStatusEnum::class,
        'payment_method' => InvoicePaymentMethodEnum::class,
        'items' => 'array',
        'subtotal' => 'decimal:2',
        'vat' => 'decimal:2',
        'total' => 'decimal:2',
        'icv' => 'integer',
        'confirmed_at' => 'datetime',
        'issued_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', SubscriptionInvoiceStatusEnum::Issued->value);
    }

    /**
     * The recognised revenue across issued sales — drafts are not revenue, so they are
     * excluded here rather than at each call site.
     *
     * @return array{issued_count: int, revenue: float, vat: float, total: float}
     */
    public static function recognisedTotals(): array
    {
        $totals = static::query()
            ->issued()
            ->selectRaw('COUNT(*) as issued_count')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as revenue')
            ->selectRaw('COALESCE(SUM(vat), 0) as vat')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->first();

        return [
            'issued_count' => (int) $totals->issued_count,
            'revenue' => round((float) $totals->revenue, 2),
            'vat' => round((float) $totals->vat, 2),
            'total' => round((float) $totals->total, 2),
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === SubscriptionInvoiceStatusEnum::Draft;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }
}
