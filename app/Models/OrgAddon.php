<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paid capability an organization holds above its plan.
 */
class OrgAddon extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'key',
        'is_active',
        'price_monthly',
        'activated_at',
        'expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_monthly' => 'decimal:2',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Add-ons currently granting anything: switched on and not lapsed.
     *
     * A null expiry runs until it is switched off, which is the normal case for one
     * billed alongside the subscription.
     */
    public function scopeGranting(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }

    public function isGranting(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
