<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dunning action against a subscription. The unique (organization, key) makes each
 * stage fire at most once per period; the rows also feed the console activity feed.
 */
class DunningLog extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'key',
        'stage',
        'message',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
