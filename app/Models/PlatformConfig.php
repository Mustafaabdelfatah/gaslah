<?php

namespace App\Models;

use App\Services\Platform\PlatformConfigStore;
use Illuminate\Database\Eloquent\Model;

/**
 * A singleton key/value store for platform-operator settings. String primary key,
 * JSON value, no created_at. Read and written through {@see PlatformConfigStore}.
 */
class PlatformConfig extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
        'updated_at' => 'datetime',
    ];
}
