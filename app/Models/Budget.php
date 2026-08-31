<?php

namespace App\Models;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned spend for one expense category in one month.
 *
 * Budgets never reach the ledger — they are the yardstick the posted expenses
 * are measured against.
 */
class Budget extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'category',
        'month',
        'amount',
        'note',
        'created_by_id',
    ];

    protected $casts = [
        'category' => ExpenseCategoryEnum::class,
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Lines planning the given month (YYYY-MM).
     */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->where('month', $month);
    }
}
