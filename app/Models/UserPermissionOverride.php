<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An explicit permission set that replaces a staff member's role defaults.
 *
 * The row's existence is the signal, not its contents: an override with no items
 * grants nothing at all, which is different from having no override and falling back
 * to the role. Clearing an override means deleting the row.
 */
class UserPermissionOverride extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
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

    public function items(): HasMany
    {
        return $this->hasMany(UserPermissionOverrideItem::class);
    }
}
