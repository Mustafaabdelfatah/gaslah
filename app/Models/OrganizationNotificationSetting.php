<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which dashboard alerts an organization wants raised.
 */
class OrganizationNotificationSetting extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'is_enabled',
        'late_orders',
        'delivery_requests',
        'ready_orders',
        'online_payments',
        'unpaid_orders',
    ];

    /**
     * Mirrors the column defaults. An organization that has never saved this panel is
     * answered with an unsaved model, and Eloquent does not read defaults out of the
     * schema — so they have to be stated here too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
        'late_orders' => true,
        'delivery_requests' => true,
        'ready_orders' => true,
        'online_payments' => true,
        'unpaid_orders' => false,
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'late_orders' => 'boolean',
        'delivery_requests' => 'boolean',
        'ready_orders' => 'boolean',
        'online_payments' => 'boolean',
        'unpaid_orders' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
