<?php

namespace App\Models;

use App\Enum\Affiliate\AffiliateReferralStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'affiliate_id', 'organization_id', 'plan_name', 'sub_amount', 'commission', 'status', 'paid_at',
    ];

    protected $casts = [
        'status' => AffiliateReferralStatusEnum::class,
        'sub_amount' => 'decimal:2',
        'commission' => 'decimal:2',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
