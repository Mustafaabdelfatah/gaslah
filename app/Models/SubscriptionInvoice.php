<?php

namespace App\Models;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ZATCA tax invoice the SaaS operator issues against a tenant subscription.
 *
 * A draft has no chain slot and no ledger footprint; an issued invoice is immutable —
 * it holds an ICV/PIH slot in the platform-wide SUB- series and a matching journal entry.
 */
class SubscriptionInvoice extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'subscription_id',
        'charge_id',
        'invoice_no',
        'seller_name',
        'seller_vat',
        'buyer_name',
        'buyer_vat',
        'plan_name',
        'cycle',
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
        'cycle' => PlatformCycleEnum::class,
        'payment_method' => InvoicePaymentMethodEnum::class,
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

    public function isDraft(): bool
    {
        return $this->status === SubscriptionInvoiceStatusEnum::Draft;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscription::class, 'subscription_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }
}
