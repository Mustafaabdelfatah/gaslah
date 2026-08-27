<?php

namespace App\Services\Platform;

use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Support\Zatca;
use App\Support\ZatcaPhase2;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mechanics every platform-issued ZATCA series shares.
 *
 * The platform sells two things — subscriptions on a SUB- series and devices on a DEV-
 * one — and both must claim their next slot the same way: read the last issued invoice,
 * hash this one onto it, then take the slot only if the row is still a draft. Getting that
 * subtly different per series is how a chain ends up with a gap or a duplicate ICV, so it
 * is written once here and the two invoicers supply what differs: their model, and the
 * canonical string that gets hashed.
 */
class PlatformInvoiceChain
{
    /**
     * Retries for an ICV collision — two accountants confirming at the same moment.
     */
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly PlatformConfigStore $config) {}

    /**
     * Claim the next slot for a draft and freeze it as issued.
     *
     * The whole thing is one compare-and-swap: only a row that is still a draft is
     * claimed, so a second confirm updates nothing and is refused rather than overwriting
     * the winner. `$onIssued` runs inside the same transaction, which is what keeps an
     * issued invoice and its journal entry from ever existing without each other.
     *
     * @param  Builder<Model>  $issuedQuery  the series' already-issued invoices
     * @param  Closure(Model, int, string, string): string  $canonical  the string to hash
     * @param  Closure(Model): void  $onIssued  runs in-transaction once the slot is taken
     */
    public function issue(Model $draft, Builder $issuedQuery, Closure $canonical, Closure $onIssued, ?int $adminId = null): Model
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $last = (clone $issuedQuery)->orderByDesc('icv')->first();
            $icv = ($last?->icv ?? 0) + 1;
            $pih = $last?->hash ?? ZatcaPhase2::GENESIS_PIH;

            $timestamp = Zatca::timestamp(Carbon::now());
            $hash = ZatcaPhase2::sha256Base64($canonical($draft, $icv, $pih, $timestamp));

            try {
                return DB::transaction(function () use ($draft, $icv, $pih, $hash, $timestamp, $onIssued, $adminId) {
                    $now = Carbon::now();

                    $affected = $draft->newQuery()
                        ->whereKey($draft->getKey())
                        ->where('status', SubscriptionInvoiceStatusEnum::Draft->value)
                        ->update([
                            'icv' => $icv,
                            'invoice_no' => $icv,
                            'pih' => $pih,
                            'hash' => $hash,
                            'qr' => $this->qr($draft, $timestamp, $hash),
                            'status' => SubscriptionInvoiceStatusEnum::Issued->value,
                            'confirmed_at' => $now,
                            'confirmed_by_id' => $adminId,
                            'issued_at' => $now,
                        ]);

                    abort_if($affected === 0, Response::HTTP_CONFLICT, __('api.invoice_already_issued'));

                    $fresh = $draft->fresh();
                    $onIssued($fresh);

                    return $fresh;
                });
            } catch (QueryException $exception) {
                // Another confirm took this ICV first; recompute against the new tail.
                if (! $this->isDuplicateKey($exception) || $attempt >= self::MAX_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        abort(Response::HTTP_SERVICE_UNAVAILABLE, __('api.invoice_issue_failed'));
    }

    /**
     * Split a VAT-inclusive total into its net and tax parts.
     *
     * Every price the platform quotes — a plan, a device — is what the buyer pays, so the
     * tax is extracted at 15/115 rather than added on top.
     *
     * @return array{subtotal: float, vat: float, total: float}
     */
    public function splitInclusive(float $total): array
    {
        $total = round($total, 2);
        $vat = round($total * 15 / 115, 2);

        return [
            'subtotal' => round($total - $vat, 2),
            'vat' => $vat,
            'total' => $total,
        ];
    }

    /**
     * The seller identity on every platform invoice: the operator, not the tenant.
     *
     * @return array{name: string, vat: string|null}
     */
    public function seller(): array
    {
        $platform = $this->config->get('platform', []);
        $platform = is_array($platform) ? $platform : [];

        return [
            'name' => (string) ($platform['sellerName'] ?? config('app.name', 'Gaslah')),
            'vat' => $platform['sellerVat'] ?? config('services.platform.seller_vat'),
        ];
    }

    private function qr(Model $invoice, string $timestamp, string $hash): string
    {
        $seller = $this->seller();

        return ZatcaPhase2::qrPayloadV2(
            $seller['name'],
            (string) $seller['vat'],
            $timestamp,
            Zatca::money((float) $invoice->getAttribute('total')),
            Zatca::money((float) $invoice->getAttribute('vat')),
            $hash,
        );
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
