<?php

namespace App\Models;

use App\Support\SecretValue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's integration credentials and switches.
 *
 * The three secret columns encrypt and decrypt through casts, so nothing that reads or
 * writes this model has to remember to call the cipher — and a secret cannot reach the
 * database in plain text by someone forgetting.
 */
class OrganizationIntegration extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'payment_methods',
        'gateway_provider',
        'gateway_public_key',
        'gateway_secret_key',
        'messaging_enabled',
        'whatsapp_enabled',
        'whatsapp_mode',
        'whatsapp_token',
        'whatsapp_phone_id',
        'sms_enabled',
        'sms_provider',
        'sms_api_key',
        'sms_sender',
        'events',
        'templates',
    ];

    protected $casts = [
        'payment_methods' => 'array',
        'events' => 'array',
        'templates' => 'array',
        'messaging_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
    ];

    /**
     * Never serialise a secret by accident — toArray() on this model must not be the way
     * a credential reaches a response.
     */
    protected $hidden = [
        'gateway_secret_key',
        'whatsapp_token',
        'sms_api_key',
    ];

    protected function gatewaySecretKey(): Attribute
    {
        return self::secret();
    }

    protected function whatsappToken(): Attribute
    {
        return self::secret();
    }

    protected function smsApiKey(): Attribute
    {
        return self::secret();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Encrypt on the way in, decrypt on the way out.
     */
    private static function secret(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => SecretValue::decrypt($value),
            set: static fn (?string $value) => SecretValue::encrypt($value),
        );
    }
}
