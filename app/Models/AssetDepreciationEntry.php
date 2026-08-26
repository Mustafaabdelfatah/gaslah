<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's depreciation charge for an asset. The (asset, period) uniqueness is
 * what makes depreciation safe to re-run.
 */
class AssetDepreciationEntry extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'fixed_asset_id',
        'period',
        'amount',
        'journal_entry_id',
        'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
