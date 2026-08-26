<?php

namespace App\Models;

use App\Enum\Affiliate\CommissionTypeEnum;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A marketing affiliate/partner. Authenticates on its own surface (kind=affiliate) via
 * phone + OTP; there is no password.
 */
class Affiliate extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'code', 'commission_type', 'commission_rate', 'is_active', 'notes',
    ];

    protected $casts = [
        'commission_type' => CommissionTypeEnum::class,
        'commission_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }
}
