<?php

namespace App\Models;

use App\Enum\Tenancy\StaffPermissionEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single permission granted through a staff permission override.
 */
class UserPermissionOverrideItem extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_permission_override_id',
        'permission',
    ];

    protected $casts = [
        'permission' => StaffPermissionEnum::class,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function override(): BelongsTo
    {
        return $this->belongsTo(UserPermissionOverride::class, 'user_permission_override_id');
    }
}
