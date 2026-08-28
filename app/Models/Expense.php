<?php

namespace App\Models;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A posted expense, carrying a link to the journal entry it generated.
 */
class Expense extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'date',
        'category',
        'description',
        'amount',
        'vat_amount',
        'account_id',
        'paid_from',
        'reference',
        'journal_entry_id',
        'created_by_id',
    ];

    protected $casts = [
        'date' => 'date',
        'category' => ExpenseCategoryEnum::class,
        'paid_from' => ExpensePaidFromEnum::class,
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
