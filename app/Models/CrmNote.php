<?php

namespace App\Models;

use App\Enum\Crm\CrmNoteKindEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up entry against a lead or an existing tenant. Notes are not edited, so there
 * is no updated_at.
 */
class CrmNote extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_cuid',
        'lead_id',
        'organization_id',
        'kind',
        'body',
        'due_at',
        'done_at',
        'author_id',
    ];

    protected $casts = [
        'kind' => CrmNoteKindEnum::class,
        'due_at' => 'datetime',
        'done_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Tasks still outstanding.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('done_at')->where('kind', CrmNoteKindEnum::Task->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
