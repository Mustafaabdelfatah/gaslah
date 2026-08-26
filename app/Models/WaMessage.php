<?php

namespace App\Models;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaMessageStatusEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp/SMS send attempt — the source of truth for quotas and stats.
 */
class WaMessage extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'branch_id', 'customer_id', 'order_id', 'to_phone', 'channel',
        'category', 'event_key', 'template_id', 'body', 'sender_mode', 'status',
        'provider_message_id', 'error', 'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'category' => WaCategoryEnum::class,
        'status' => WaMessageStatusEnum::class,
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
