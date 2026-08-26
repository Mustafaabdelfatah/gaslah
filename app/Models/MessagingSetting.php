<?php

namespace App\Models;

/**
 * An organization's messaging configuration and platform-set limits. One row per org.
 */
class MessagingSetting extends BaseModel
{
    protected $fillable = ['organization_id', 'config', 'limits'];

    protected $casts = [
        'config' => 'array',
        'limits' => 'array',
    ];
}
