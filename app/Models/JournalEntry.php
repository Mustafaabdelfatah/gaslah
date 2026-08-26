<?php

namespace App\Models;

use App\Enum\Accounting\JournalSourceEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A balanced double-entry journal entry.
 *
 * Only the posting service creates these; it guarantees the balance, the sequential
 * entry number, and idempotency on (organization, source, ref_type, ref_id).
 */
class JournalEntry extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'entry_no',
        'date',
        'memo',
        'source',
        'ref_type',
        'ref_id',
        'branch_id',
        'created_by_id',
    ];

    protected $casts = [
        'entry_no' => 'integer',
        'date' => 'date',
        'source' => JournalSourceEnum::class,
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
