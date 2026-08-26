<?php

namespace App\Models;

use App\Enum\Tenancy\SecurityActionEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of one authentication attempt.
 *
 * Rows are written for failures that matched no account as well, since the lockout
 * counter must see attempts against addresses that do not exist.
 */
class SecurityLog extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email',
        'surface',
        'ip_address',
        'action',
        'reason',
        'user_agent',
    ];

    protected $casts = [
        'action' => SecurityActionEnum::class,
        'surface' => SecuritySurfaceEnum::class,
        'created_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow to a single lockout bucket.
     *
     * Counting by address alone would let anyone keep a known account locked out
     * indefinitely, so the address is paired with the caller's IP.
     */
    public function scopeForLockout(Builder $query, ?string $email, ?string $ip, SecuritySurfaceEnum $surface): Builder
    {
        return $query->where('email', $email)
            ->where('ip_address', $ip)
            ->where('surface', $surface->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
