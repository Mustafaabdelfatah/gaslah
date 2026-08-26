<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single delivery status change, stamped with one `at` time (no created/updated pair).
 */
class DeliveryStatusHistory extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'user_id',
        'from_status',
        'to_status',
        'note',
        'at',
    ];

    protected $casts = [
        'at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(DeliveryRequest::class, 'request_id');
    }
}
