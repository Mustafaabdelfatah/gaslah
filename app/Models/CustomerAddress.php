<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_cuid',
        'customer_id',
        'label',
        'district',
        'street',
        'details',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
