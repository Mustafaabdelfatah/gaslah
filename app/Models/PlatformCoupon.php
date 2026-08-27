<?php

namespace App\Models;

use App\Enum\Platform\PlatformCouponTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A discount coupon for tenant subscriptions. Redemption never exceeds the cap: the
 * counter is bumped through an atomic conditional update, so two concurrent redemptions
 * of the last slot cannot both succeed.
 */
class PlatformCoupon extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_redemptions',
        'redemptions',
        'applies_to_plan_id',
        'expires_at',
        'is_active',
        'note',
    ];

    protected $casts = [
        'type' => PlatformCouponTypeEnum::class,
        'value' => 'decimal:2',
        'max_redemptions' => 'integer',
        'redemptions' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */

    /**
     * Coupons that could still be used: active, unexpired, and under their cap.
     *
     * The SQL mirrors {@see isRedeemable} minus its plan check, so a listing filtered on
     * "redeemable" agrees with what redemption will actually allow.
     */
    public function scopeRedeemable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
            ->where(fn (Builder $q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'));
    }

    /**
     * The coupon's effect on a price.
     *
     * @return array{price: float, extra_months: int, discount: float}
     */
    public function effect(float $price): array
    {
        $value = (float) $this->value;

        $newPrice = match ($this->type) {
            PlatformCouponTypeEnum::Percent => round($price * (1 - min(100, max(0, $value)) / 100), 2),
            PlatformCouponTypeEnum::Fixed => round(max(0, $price - $value), 2),
            PlatformCouponTypeEnum::FreeMonths => round($price, 2),
        };

        return [
            'price' => $newPrice,
            'extra_months' => $this->type === PlatformCouponTypeEnum::FreeMonths ? (int) $value : 0,
            'discount' => round($price - $newPrice, 2),
        ];
    }

    /**
     * Whether the coupon can currently be used, optionally for a specific plan.
     */
    public function isRedeemable(?int $planId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions) {
            return false;
        }

        return $this->applies_to_plan_id === null || $this->applies_to_plan_id === $planId;
    }

    /**
     * Consume one redemption atomically. Returns false if the cap was reached first.
     */
    public function redeem(): bool
    {
        if ($this->max_redemptions === null) {
            $this->increment('redemptions');

            return true;
        }

        $affected = static::query()
            ->whereKey($this->getKey())
            ->whereColumn('redemptions', '<', 'max_redemptions')
            ->update(['redemptions' => $this->getConnection()->raw('redemptions + 1')]);

        if ($affected > 0) {
            $this->refresh();

            return true;
        }

        return false;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'applies_to_plan_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon): void {
            if ($coupon->code !== null) {
                $coupon->code = strtoupper($coupon->code);
            }
        });
    }
}
