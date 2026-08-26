<?php

namespace App\Models;

/**
 * Per-organization order auto-advance automation settings.
 */
class AutomationSetting extends BaseModel
{
    protected $fillable = ['organization_id', 'enabled', 'delays'];

    protected $casts = [
        'enabled' => 'boolean',
        'delays' => 'array',
    ];
}
