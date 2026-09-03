<?php

namespace App\Models;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\RecurringFrequencyEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring AP bill or directly-paid expense schedule.
 */
class RecurringBill extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'name',
        'category',
        'amount',
        'vat_amount',
        'supplier_id',
        'branch_id',
        'paid_from',
        'frequency',
        'anchor_day',
        'due_days',
        'next_run',
        'last_run',
        'generated_count',
        'is_active',
        'description',
    ];

    protected $casts = [
        'category' => ExpenseCategoryEnum::class,
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'paid_from' => ExpensePaidFromEnum::class,
        'frequency' => RecurringFrequencyEnum::class,
        'anchor_day' => 'integer',
        'due_days' => 'integer',
        'next_run' => 'date',
        'last_run' => 'date',
        'generated_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function generatedPayables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }
}
