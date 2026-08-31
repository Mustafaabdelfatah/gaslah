<?php

namespace App\Models;

use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An operating location within an organization.
 *
 * The branch is the unit writes are attributed to: an order, shift or journal entry
 * lands in the branch the till is open at, never in whichever branch the reader
 * happens to be filtering by.
 */
class Branch extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    /**
     * Code reserved for the primary branch created with every organization.
     */
    public const MAIN_CODE = 'MAIN';

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'name',
        'code',
        'address',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes && Casts methods
    |--------------------------------------------------------------------------
    */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value === null ? null : Str::upper($value),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isMain(): bool
    {
        return $this->code === self::MAIN_CODE;
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

    public function userBranches(): HasMany
    {
        return $this->hasMany(UserBranch::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches')
            ->withPivot('role');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
