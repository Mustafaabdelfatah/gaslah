<?php

namespace App\Models;

use App\Enum\Catalog\CustomerTypeEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * A laundry customer.
 *
 * A customer authenticates on the portal surface (kind = customer) via phone + OTP; the
 * Sanctum token is its only credential (no password). `wallet_balance` is deliberately
 * absent from $fillable: it is the locked source of truth for stored value and is only
 * ever written by the wallet service inside a row-locked transaction.
 */
class Customer extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, HasFactory, LogsActivityOptions, SoftDeletes;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'name',
        'phone',
        'email',
        'address',
        'birth_date',
        'type',
        'credit_limit',
        'preferences',
    ];

    protected $casts = [
        'type' => CustomerTypeEnum::class,
        'credit_limit' => 'decimal:2',
        'wallet_balance' => 'decimal:2',
        'birth_date' => 'date',
        'preferences' => 'array',
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

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
