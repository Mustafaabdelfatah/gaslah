<?php

namespace App\Models;

use App\Enum\Accounting\AccountTypeEnum;
use App\Enum\Accounting\SystemAccountEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chart-of-accounts entry for one organization.
 *
 * The balance is never stored; it is computed from journal line totals whenever a
 * report needs it.
 */
class Account extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'code',
        'name',
        'name_en',
        'type',
        'parent_id',
        'is_system',
        'system_key',
        'is_active',
    ];

    protected $casts = [
        'type' => AccountTypeEnum::class,
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeSystemKey(Builder $query, SystemAccountEnum|string $key): Builder
    {
        return $query->where('system_key', $key instanceof SystemAccountEnum ? $key->value : $key);
    }

    /**
     * A system account's structural fields are frozen; only its display name may
     * change. This keeps the posting engine's system-key wiring stable.
     */
    public function isStructurallyLocked(): bool
    {
        return $this->is_system;
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
