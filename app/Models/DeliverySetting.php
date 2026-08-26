<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's delivery configuration and the platform-controlled availability of
 * the three delivery methods. One row per organization.
 */
class DeliverySetting extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'settings',
        'available_methods',
    ];

    protected $casts = [
        'settings' => 'array',
        'available_methods' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
