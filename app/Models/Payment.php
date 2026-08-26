<?php

namespace App\Models;

use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\PaymentVerifyModeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_cuid',
        'order_id',
        'method',
        'amount',
        'reference',
        'verify_mode',
        'shift_id',
        'via_gateway',
        'settlement_id',
    ];

    protected $casts = [
        'method' => PaymentMethodEnum::class,
        'verify_mode' => PaymentVerifyModeEnum::class,
        'amount' => 'decimal:2',
        'via_gateway' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
