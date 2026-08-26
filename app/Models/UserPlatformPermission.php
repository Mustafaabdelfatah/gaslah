<?php

namespace App\Models;

use App\Enum\Tenancy\PlatformPermissionEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform permission granted explicitly to an administrator.
 *
 * Owners hold no rows here — that role bypasses permission checks — and a Viewer
 * legitimately has none.
 */
class UserPlatformPermission extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'permission',
    ];

    protected $casts = [
        'permission' => PlatformPermissionEnum::class,
    ];

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
