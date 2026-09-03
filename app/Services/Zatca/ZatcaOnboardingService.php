<?php

namespace App\Services\Zatca;

use App\Contracts\ZatcaCsrGenerator;
use App\Models\Organization;
use App\Models\ZatcaRegistration;
use App\Support\SecretValue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZatcaOnboardingService
{
    public function __construct(
        private readonly ZatcaCsrGenerator $csrGenerator,
        private readonly ZatcaGatewayClient $gateway,
    ) {}

    /**
     * The safe onboarding fields consumed by the staff settings screen.
     *
     * @return array<string, mixed>
     */
    public function status(Organization $organization): array
    {
        $registration = $this->registration($organization);
        $hasCsr = $registration?->hasCsr() === true && $this->filesExist($registration);
        // A CSID without its matching private key cannot be used safely. Treat lost
        // key material as not ready so the UI guides the manager through regeneration.
        $hasComplianceCsid = $hasCsr && ($registration?->hasComplianceCsid() ?? false);
        $hasProductionCsid = $hasCsr && ($registration?->hasProductionCsid() ?? false);

        return [
            'environment' => $registration?->environment ?? config('zatca.environment', 'sandbox'),
            'base_url' => config('zatca.base_url'),
            'portal_url' => config('zatca.portal_url'),
            'has_csr' => $hasCsr,
            'csr_ready' => $hasCsr,
            'has_compliance_csid' => $hasComplianceCsid,
            // Compatibility for the reference screen: this means only that the OTP
            // exchange produced a compliance CSID, not that reporting is production-ready.
            'onboarded' => $hasComplianceCsid,
            'compliance_passed' => false,
            'has_production_csid' => $hasProductionCsid,
            'reporting_ready' => false,
            'certificate_fingerprint' => $hasComplianceCsid ? $registration?->cert_fingerprint : null,
            'compliance_request_id' => $hasComplianceCsid ? $registration?->compliance_request_id : null,
            'complied_at' => $hasComplianceCsid ? $registration?->complied_at : null,
            'onboarded_at' => $hasProductionCsid ? $registration?->onboarded_at : null,
        ];
    }

    /**
     * @return array{ok:bool,subject:array<string,string>,csr_pem_head:string,csr_base64:string}
     */
    public function generateCsr(Organization $organization, bool $force = false): array
    {
        abort_if(blank($organization->vat_number), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.org_not_vat_registered'));

        $registration = $this->registration($organization);

        if ($registration?->hasCsr() && $this->filesExist($registration) && ! $force) {
            return $this->presentCsr((string) $registration->csr_pem, []);
        }

        $generated = $this->csrGenerator->generate($organization);
        $privateKeyPath = $this->privateKeyPath($organization->getKey());
        $csrPath = $this->csrPath($organization->getKey());
        $disk = Storage::disk((string) config('zatca.storage_disk', 'local'));
        $encryptedPrivateKey = SecretValue::encrypt($generated['private_key_pem']);

        if (
            $encryptedPrivateKey === null
            || ! $disk->put($privateKeyPath, $encryptedPrivateKey)
            || ! $disk->put($csrPath, $generated['csr_pem'])
        ) {
            throw new RuntimeException('Unable to persist the ZATCA signing material.');
        }

        $this->tightenFilePermissions($privateKeyPath, $csrPath);

        $registration ??= new ZatcaRegistration(['organization_id' => $organization->getKey()]);
        $registration->fill([
            'environment' => (string) config('zatca.environment', 'sandbox'),
            'vat_number' => $organization->vat_number,
            'csr_pem' => $generated['csr_pem'],
            'private_key_path' => $privateKeyPath,
        ]);

        // Every generated keypair is a new EGS identity. Whether regeneration was
        // explicit or caused by missing files, credentials bound to the old key must
        // never remain marked as usable.
        $registration->fill([
            'csid_cert_pem' => null,
            'cert_fingerprint' => null,
            'compliance_binary_token' => null,
            'compliance_secret' => null,
            'compliance_request_id' => null,
            'production_binary_token' => null,
            'production_secret' => null,
            'production_request_id' => null,
            'complied_at' => null,
            'onboarded_at' => null,
        ]);

        $registration->save();

        return $this->presentCsr($generated['csr_pem'], $generated['subject']);
    }

    /**
     * @return array{ok:bool,gateway_status:int,request_id:?string,has_compliance_csid:bool}
     */
    public function comply(Organization $organization, string $otp): array
    {
        abort_if(blank($organization->vat_number), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.org_not_vat_registered'));

        $registration = $this->registration($organization);
        abort_if(
            $registration === null || ! $registration->hasCsr() || ! $this->filesExist($registration),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.zatca_csr_missing'),
        );

        $result = $this->gateway->complianceCsid(base64_encode((string) $registration->csr_pem), $otp);
        $body = $result['body'] ?? [];
        $token = is_string($body['binarySecurityToken'] ?? null) ? trim($body['binarySecurityToken']) : '';
        $secret = is_string($body['secret'] ?? null) ? trim($body['secret']) : '';
        $requestId = is_scalar($body['requestID'] ?? null) ? (string) $body['requestID'] : null;

        if (! $result['ok'] || $token === '' || $secret === '' || blank($requestId)) {
            return [
                'ok' => false,
                'gateway_status' => $result['status'],
                'request_id' => null,
                'has_compliance_csid' => false,
            ];
        }

        [$certificate, $fingerprint] = $this->certificateMetadata($token);

        $registration->fill([
            'compliance_binary_token' => $token,
            'compliance_secret' => $secret,
            'compliance_request_id' => $requestId,
            'csid_cert_pem' => $certificate,
            'cert_fingerprint' => $fingerprint,
            'complied_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'gateway_status' => $result['status'],
            'request_id' => $requestId,
            'has_compliance_csid' => true,
        ];
    }

    private function registration(Organization $organization): ?ZatcaRegistration
    {
        return ZatcaRegistration::query()->forOrganization($organization->getKey())->first();
    }

    private function filesExist(ZatcaRegistration $registration): bool
    {
        $disk = Storage::disk((string) config('zatca.storage_disk', 'local'));

        return filled($registration->private_key_path)
            && $disk->exists((string) $registration->private_key_path)
            && $disk->exists($this->csrPath((int) $registration->organization_id));
    }

    /**
     * @param  array<string, string>  $subject
     * @return array{ok:bool,subject:array<string,string>,csr_pem_head:string,csr_base64:string}
     */
    private function presentCsr(string $csr, array $subject): array
    {
        return [
            'ok' => true,
            'subject' => $subject,
            'csr_pem_head' => implode("\n", array_slice(preg_split('/\r?\n/', trim($csr)) ?: [], 0, 3)),
            'csr_base64' => base64_encode($csr),
        ];
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function certificateMetadata(string $token): array
    {
        $der = base64_decode(preg_replace('/\s+/', '', $token) ?? '', true);

        if ($der === false || $der === '') {
            return [null, null];
        }

        $certificate = str_starts_with($der, '-----BEGIN CERTIFICATE-----')
            ? $der
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";

        return [$certificate, hash('sha256', $der)];
    }

    private function privateKeyPath(int $organizationId): string
    {
        return trim((string) config('zatca.storage_path', 'zatca'), '/')."/{$organizationId}/ec-private-key.pem.enc";
    }

    private function csrPath(int $organizationId): string
    {
        return trim((string) config('zatca.storage_path', 'zatca'), '/')."/{$organizationId}/taxpayer.csr";
    }

    private function tightenFilePermissions(string ...$paths): void
    {
        $disk = Storage::disk((string) config('zatca.storage_disk', 'local'));

        foreach ($paths as $path) {
            try {
                @chmod($disk->path($path), 0600);
                @chmod(dirname($disk->path($path)), 0700);
            } catch (Throwable) {
                // Non-local disks enforce access through their own visibility policy.
            }
        }
    }
}
