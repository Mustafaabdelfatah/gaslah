<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Which dashboard alerts the organization wants raised.
 *
 * Every switch is required: the screen saves the whole panel, and an absent key would
 * silently mean "off" while reading as "unchanged".
 */
class UpdateNotificationSettingsRequest extends TenantFormRequest
{
    /**
     * @var array<int, string>
     */
    private const SWITCHES = [
        'is_enabled',
        'late_orders',
        'delivery_requests',
        'ready_orders',
        'online_payments',
        'unpaid_orders',
    ];

    public function rules(): array
    {
        return array_fill_keys(self::SWITCHES, ['required', 'boolean']);
    }

    /**
     * @return array<string, bool>
     */
    public function switches(): array
    {
        $switches = [];

        foreach (self::SWITCHES as $switch) {
            $switches[$switch] = $this->boolean($switch);
        }

        return $switches;
    }
}
