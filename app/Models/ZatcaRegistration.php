<?php

namespace App\Models;

use App\Support\SecretValue;
use Database\Factories\ZatcaRegistrationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tenant's ZATCA onboarding material.
 *
 * CSID credentials are encrypted transparently and hidden from serialization.
 */
class ZatcaRegistration extends BaseModel
{
    /** @use HasFactory<ZatcaRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'environment',
        'vat_number',
        'csr_pem',
        'private_key_path',
        'csid_cert_pem',
        'cert_fingerprint',
        'compliance_binary_token',
        'compliance_secret',
        'compliance_request_id',
        'production_binary_token',
        'production_secret',
        'production_request_id',
        'complied_at',
        'onboarded_at',
    ];

    protected $hidden = [
        'csr_pem',
        'private_key_path',
        'csid_cert_pem',
        'compliance_binary_token',
        'compliance_secret',
        'production_binary_token',
        'production_secret',
    ];

    protected $casts = [
        'complied_at' => 'datetime',
        'onboarded_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasCsr(): bool
    {
        return filled($this->csr_pem) && filled($this->private_key_path);
    }

    public function hasComplianceCsid(): bool
    {
        return filled($this->compliance_binary_token) && filled($this->compliance_secret);
    }

    public function hasProductionCsid(): bool
    {
        return filled($this->production_binary_token) && filled($this->production_secret);
    }

    protected function complianceBinaryToken(): Attribute
    {
        return self::secret();
    }

    protected function complianceSecret(): Attribute
    {
        return self::secret();
    }

    protected function productionBinaryToken(): Attribute
    {
        return self::secret();
    }

    protected function productionSecret(): Attribute
    {
        return self::secret();
    }

    private static function secret(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value) => SecretValue::decrypt($value),
            set: static fn (?string $value) => SecretValue::encrypt($value),
        );
    }
}
