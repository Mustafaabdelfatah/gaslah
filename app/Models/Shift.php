<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier shift with a cash float, drawer movements, and a close-out variance.
 */
class Shift extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_float',
        'expected_cash',
        'actual_cash',
        'variance',
        'note',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    public function scopeInBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn('branch_id', $branchIds);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'shift_id');
    }
}
