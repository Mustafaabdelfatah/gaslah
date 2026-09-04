<?php

namespace App\Services\Zatca;

use Illuminate\Support\Facades\Http;
use Throwable;

class ZatcaGatewayClient
{
    /**
     * Exchange a taxpayer OTP and CSR for a compliance CSID.
     *
     * @return array{ok:bool,status:int,body:?array<string,mixed>}
     */
    public function complianceCsid(string $csrBase64, string $otp): array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('zatca.base_url'), '/'))
                ->timeout((int) config('zatca.timeout', 15))
                ->connectTimeout(5)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Accept-Version' => (string) config('zatca.accept_version', 'V2'),
                    'Accept-Language' => 'en',
                    'OTP' => $otp,
                ])
                ->post('/compliance', ['csr' => $csrBase64]);

            $body = $response->json();

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => is_array($body) ? $body : null,
            ];
        } catch (Throwable) {
            return ['ok' => false, 'status' => 0, 'body' => null];
        }
    }
}
