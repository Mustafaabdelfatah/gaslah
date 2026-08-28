<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's dashboard alert switches.
 */
class NotificationSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'is_enabled' => (bool) $this->is_enabled,
            'late_orders' => (bool) $this->late_orders,
            'delivery_requests' => (bool) $this->delivery_requests,
            'ready_orders' => (bool) $this->ready_orders,
            'online_payments' => (bool) $this->online_payments,
            'unpaid_orders' => (bool) $this->unpaid_orders,
        ];
    }
}
