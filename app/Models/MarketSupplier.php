<?php

namespace App\Models;

use App\Enum\Market\MarketCommissionTypeEnum;
use App\Enum\Market\MarketSupplierStatusEnum;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A business selling supplies to laundries through the market.
 *
 * Its own sign-in surface, like the driver and affiliate apps: a supplier is not a member
 * of any tenant, and sells to many.
 */
class MarketSupplier extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'name',
        'email',
        'phone',
        'password',
        'status',
        'description',
        'city',
        'logo_url',
        'commission_type',
        'commission_value',
        'approved_at',
    ];

    /**
     * The hash must never reach a response, whatever serialises this model.
     */
    protected $hidden = ['password'];

    protected $casts = [
        'status' => MarketSupplierStatusEnum::class,
        'commission_type' => MarketCommissionTypeEnum::class,
        'commission_value' => 'decimal:2',
        'approved_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Stored lower-cased so sign-in matches however the supplier types their address.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value === null ? null : Str::lower(trim($value)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */

    /**
     * Suppliers whose products may be offered to buyers.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', MarketSupplierStatusEnum::Approved->value);
    }

    /**
     * The commission the platform takes from this supplier's orders, falling back to the
     * platform default when they have no rate of their own.
     *
     * @return array{type: MarketCommissionTypeEnum, value: float}
     */
    public function commission(): array
    {
        $type = $this->commission_type ?? MarketCommissionTypeEnum::Percent;

        $value = $this->commission_value !== null
            ? (float) $this->commission_value
            : ($type === MarketCommissionTypeEnum::Percent ? MarketCommissionTypeEnum::DEFAULT_PERCENT : 0.0);

        return ['type' => $type, 'value' => $value];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function products(): HasMany
    {
        return $this->hasMany(MarketProduct::class, 'supplier_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MarketOrder::class, 'supplier_id');
    }
}
