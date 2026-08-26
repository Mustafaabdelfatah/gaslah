<?php

namespace App\Models;

use App\Enum\Accounting\AssetCategoryEnum;
use App\Enum\Accounting\AssetStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A straight-line depreciated fixed asset. Book value is always computed.
 */
class FixedAsset extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'name',
        'category',
        'cost',
        'purchase_date',
        'useful_life_months',
        'salvage_value',
        'method',
        'accumulated_depreciation',
        'last_depreciation_date',
        'status',
        'acquisition_posted',
        'acquisition_paid_from',
        'acquisition_journal_entry_id',
        'note',
        'disposed_date',
        'disposal_proceeds',
        'disposal_gain',
        'disposal_via',
        'disposal_journal_entry_id',
    ];

    protected $casts = [
        'category' => AssetCategoryEnum::class,
        'status' => AssetStatusEnum::class,
        'cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'disposal_proceeds' => 'decimal:2',
        'disposal_gain' => 'decimal:2',
        'useful_life_months' => 'integer',
        'acquisition_posted' => 'boolean',
        'purchase_date' => 'date',
        'last_depreciation_date' => 'date',
        'disposed_date' => 'date',
    ];

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    public function monthlyDepreciation(): float
    {
        return round(((float) $this->cost - (float) $this->salvage_value) / $this->useful_life_months, 2);
    }

    public function depreciableRemaining(): float
    {
        return round((float) $this->cost - (float) $this->salvage_value - (float) $this->accumulated_depreciation, 2);
    }

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

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(AssetDepreciationEntry::class);
    }
}
