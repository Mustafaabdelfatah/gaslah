<?php

namespace App\Models;

use App\Enum\Accounting\PayableSettlementMethodEnum;
use App\Enum\Accounting\PayableStatusEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier-bill metadata for one AP-funded expense.
 */
class Payable extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'expense_id',
        'supplier_id',
        'bill_no',
        'issue_date',
        'due_date',
        'status',
        'paid_at',
        'paid_via',
        'paid_journal_entry_id',
        'recurring_bill_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'status' => PayableStatusEnum::class,
        'paid_at' => 'datetime',
        'paid_via' => PayableSettlementMethodEnum::class,
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PayableStatusEnum::Open->value);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paidJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'paid_journal_entry_id');
    }

    public function recurringBill(): BelongsTo
    {
        return $this->belongsTo(RecurringBill::class);
    }
}
