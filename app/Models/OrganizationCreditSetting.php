<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationCreditSetting extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'is_enabled',
        'default_limit',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'default_limit' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
