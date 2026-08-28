<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operating cost of the platform.
 *
 * When a partner fronts the money, the expense doubles as a debt back to them: it is
 * outstanding until reimbursed, and that balance counts toward what the partner is owed.
 */
class PlatformExpense extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'date',
        'category',
        'amount',
        'note',
        'created_by_id',
        'paid_by_partner_id',
        'reimbursed_at',
        'reimbursed_by_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'reimbursed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Fronted by a partner and not yet paid back.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotNull('paid_by_partner_id')->whereNull('reimbursed_at');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(PlatformPartner::class, 'paid_by_partner_id');
    }
}
