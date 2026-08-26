<?php

namespace App\Models;

use App\Enum\Global\OtpPurposeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A hashed one-time password. The hash is hidden and the code is single-use.
 */
class OtpCode extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'phone',
        'code_hash',
        'purpose',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'purpose' => OtpPurposeEnum::class,
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * The latest still-unconsumed code for a purpose in one organization + phone.
     */
    public function scopeActiveFor(Builder $query, ?int $organizationId, string $phone, OtpPurposeEnum $purpose): Builder
    {
        return $query->where('organization_id', $organizationId)
            ->where('phone', $phone)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->latest('id');
    }
}
