<?php

namespace App\Models;

use App\Enum\Support\SupportAuthorTypeEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a support thread. Messages are never edited, so there is no updated_at.
 */
class SupportTicketMessage extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_cuid',
        'ticket_id',
        'author_type',
        'author_id',
        'body',
    ];

    protected $casts = [
        'author_type' => SupportAuthorTypeEnum::class,
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
