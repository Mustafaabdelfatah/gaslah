<?php

namespace App\Models;

use App\Enum\Orders\OrderStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends BaseModel
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'legacy_cuid',
        'order_id',
        'user_id',
        'from_status',
        'to_status',
        'note',
        'at',
    ];

    protected $casts = [
        'from_status' => OrderStatusEnum::class,
        'to_status' => OrderStatusEnum::class,
        'at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
