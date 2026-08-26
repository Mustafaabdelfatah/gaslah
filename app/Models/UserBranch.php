<?php

namespace App\Models;

use App\Enum\Tenancy\StaffRoleEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership of a user in a branch, and the authoritative record of their role there.
 *
 * A user may hold a different role in each branch; users.role only mirrors the
 * highest of them.
 */
class UserBranch extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'legacy_cuid',
        'user_id',
        'branch_id',
        'role',
    ];

    protected $casts = [
        'role' => StaffRoleEnum::class,
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
