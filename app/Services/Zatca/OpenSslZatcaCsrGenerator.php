<?php

namespace App\Services\Zatca;

use App\Contracts\ZatcaCsrGenerator;
use App\Models\Organization;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates the ZATCA EC keypair and custom-extension CSR through the configured
 * OpenSSL binary. Plaintext material exists only in a short-lived work directory;
 * the onboarding service persists the private key encrypted.
 */
class OpenSslZatcaCsrGenerator implements ZatcaCsrGenerator
{
    public function generate(Organization $organization): array
    {
        $directory = storage_path('framework/zatca/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700, true);

        $configPath = $directory.DIRECTORY_SEPARATOR.'csr-config.cnf';
        $keyPath = $directory.DIRECTORY_SEPARATOR.'ec-private-key.pem';
        $csrPath = $directory.DIRECTORY_SEPARATOR.'taxpayer.csr';
        $subject = $this->subjectFor($organization);

        try {
            File::put($configPath, $this->configFor($subject));
            $this->tightenPermissions($configPath);
            $this->run([
                $this->openssl(), 'ecparam', '-name', 'secp256k1', '-genkey', '-noout', '-out', $keyPath,
            ]);
            $this->tightenPermissions($keyPath);
            $this->run([
                $this->openssl(), 'req', '-new', '-sha256', '-key', $keyPath, '-config', $configPath, '-out', $csrPath,
            ]);
            $this->tightenPermissions($csrPath);

            $privateKey = File::get($keyPath);
            $csr = File::get($csrPath);

            if ($privateKey === '' || $csr === '') {
                throw new RuntimeException('OpenSSL did not produce the expected ZATCA material.');
            }

            return [
                'private_key_pem' => $privateKey,
                'csr_pem' => $csr,
                'subject' => $subject,
            ];
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * @return array<string, string>
     */
    private function subjectFor(Organization $organization): array
    {
        $environment = (string) config('zatca.environment', 'sandbox');

        return [
            'common_name' => $this->ascii((string) config('zatca.csr.common_name', 'GaslahPOS-EGS')),
            'organization_name' => $this->ascii($organization->name ?? 'Taxpayer'),
            'organization_unit' => $this->ascii($organization->cr_number ?? $organization->vat_number ?? 'NA'),
            'country' => $this->ascii((string) config('zatca.csr.country', 'SA')),
            'serial_number' => sprintf(
                '1-%s|2-%s|3-%s',
                $this->ascii((string) config('zatca.csr.egs_solution_name', 'GaslahPOS')),
                $this->ascii((string) config('zatca.csr.egs_model', 'Laravel13')),
                $organization->getKey(),
            ),
            'vat_number' => $this->ascii((string) $organization->vat_number),
            'invoice_type' => $this->ascii((string) config('zatca.csr.invoice_type', '1100')),
            'registered_address' => $this->ascii($organization->address ?? (string) config('zatca.csr.registered_address', 'Riyadh, KSA')),
            'business_category' => $this->ascii((string) config('zatca.csr.business_category', 'Laundry')),
            'template' => $this->ascii((string) config("zatca.csr.templates.{$environment}", 'TSTZATCA-Code-Signing')),
        ];
    }

    /**
     * @param  array<string, string>  $subject
     */
    private function configFor(array $subject): string
    {
        return <<<CNF
        oid_section = OIDs

        [ OIDs ]
        certificateTemplateName = 1.3.6.1.4.1.311.20.2

        [ req ]
        prompt = no
        utf8 = yes
        distinguished_name = DN
        req_extensions = v3_req

        [ DN ]
        CN = {$subject['common_name']}
        OU = {$subject['organization_unit']}
        O = {$subject['organization_name']}
        C = {$subject['country']}

        [ v3_req ]
        basicConstraints = CA:FALSE
        keyUsage = digitalSignature, nonRepudiation, keyEncipherment
        certificateTemplateName = ASN1:PRINTABLESTRING:{$subject['template']}
        subjectAltName = dirName:alt_names

        [ alt_names ]
        SN = {$subject['serial_number']}
        UID = {$subject['vat_number']}
        title = {$subject['invoice_type']}
        registeredAddress = {$subject['registered_address']}
        businessCategory = {$subject['business_category']}
        CNF;
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): void
    {
        $result = Process::timeout((int) config('zatca.openssl_timeout', 20))->run($command);

        if (! $result->successful()) {
            throw new RuntimeException('OpenSSL could not generate the ZATCA signing request.');
        }
    }

    private function openssl(): string
    {
        return (string) config('zatca.openssl_bin', 'openssl');
    }

    private function tightenPermissions(string $path): void
    {
        // chmod is intentionally best effort for Windows; Unix hosts get a strict
        // owner-only mode before any plaintext key material is read back.
        @chmod($path, 0600);
    }

    private function ascii(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $ascii = $transliterated === false
            ? ''
            : (string) preg_replace('/[^A-Za-z0-9 .,:@_+|()\/-]/', ' ', $transliterated);
        $ascii = trim((string) preg_replace('/\s+/', ' ', $ascii));

        return $ascii === '' ? 'NA' : $ascii;
    }
}
