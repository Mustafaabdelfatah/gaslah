<?php

namespace App\Models;

use App\Enum\Platform\PlatformAuditActionEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of a platform-admin action taken against a tenant
 * (suspend, entitlement change, impersonation, …).
 */
class PlatformAuditLog extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'organization_id',
        'action',
        'meta',
    ];

    protected $casts = [
        'action' => PlatformAuditActionEnum::class,
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
