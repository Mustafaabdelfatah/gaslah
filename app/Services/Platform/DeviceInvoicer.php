<?php

namespace App\Services\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Models\DeviceSale;
use App\Models\Organization;
use App\Models\PlatformDevice;
use App\Support\Zatca;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step ZATCA billing for hardware the platform sells, on the DEV- chain.
 *
 * The same shape as subscription billing — a freely-deletable draft, then a confirm that
 * claims a chain slot and posts revenue in one transaction — but a separate sequence and a
 * separate revenue account, so recurring and one-off income never blur together.
 */
class DeviceInvoicer
{
    public function __construct(
        private readonly PlatformBooks $books,
        private readonly PlatformInvoiceChain $chain,
    ) {}

    /**
     * Draft an invoice for a set of devices.
     *
     * Line prices are snapshotted from the catalogue as it stands now: re-pricing a device
     * later must not rewrite what a past buyer was charged.
     *
     * @param  array<int, array{device_id: int, qty: int}>  $lines
     * @param  array{bank_name?: string|null, transfer_ref?: string|null, gateway_name?: string|null}  $paymentMeta
     */
    public function quote(
        ?Organization $organization,
        string $buyerName,
        array $lines,
        InvoicePaymentMethodEnum $method,
        array $paymentMeta = [],
        ?string $buyerVat = null,
        ?string $notes = null,
    ): DeviceSale {
        // The platform cannot sell to itself: its books would show revenue and the matching
        // cash going to the same place.
        abort_if(
            $organization !== null && $organization->isPlatformOrg(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.device_sale_to_platform'),
        );

        $items = $this->priceLines($lines);
        $money = $this->chain->splitInclusive(array_sum(array_column($items, 'lineTotal')));
        $seller = $this->chain->seller();

        return DeviceSale::query()->create([
            'organization_id' => $organization?->getKey(),
            'buyer_name' => $buyerName,
            'buyer_vat' => $buyerVat ?? $organization?->vat_number,
            'seller_name' => $seller['name'],
            'seller_vat' => $seller['vat'],
            'items' => $items,
            'notes' => $notes,
            'subtotal' => $money['subtotal'],
            'vat' => $money['vat'],
            'total' => $money['total'],
            'payment_method' => $method->value,
            'bank_name' => $paymentMeta['bank_name'] ?? null,
            'transfer_ref' => $paymentMeta['transfer_ref'] ?? null,
            'gateway_name' => $paymentMeta['gateway_name'] ?? null,
            'status' => SubscriptionInvoiceStatusEnum::Draft->value,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Confirm a draft: claim the next DEV- slot, freeze it, and post the revenue.
     */
    public function confirm(DeviceSale $sale, ?int $adminId = null): DeviceSale
    {
        abort_unless($sale->isDraft(), Response::HTTP_CONFLICT, __('api.invoice_already_issued'));

        /** @var DeviceSale $issued */
        $issued = $this->chain->issue(
            $sale,
            DeviceSale::query()->issued(),
            fn (DeviceSale $draft, int $icv, string $pih, string $timestamp) => $this->canonical($draft, $icv, $pih, $timestamp),
            fn (DeviceSale $confirmed) => $this->books->postDeviceSale($confirmed),
            $adminId,
        );

        return $issued;
    }

    /**
     * Draft and confirm together, for recording a sale that already happened.
     *
     * Wrapped so a failed confirm takes the draft with it, rather than leaving an orphan
     * that looks like an unbilled sale.
     *
     * @param  array<int, array{device_id: int, qty: int}>  $lines
     * @param  array{bank_name?: string|null, transfer_ref?: string|null, gateway_name?: string|null}  $paymentMeta
     */
    public function issue(
        ?Organization $organization,
        string $buyerName,
        array $lines,
        InvoicePaymentMethodEnum $method,
        array $paymentMeta = [],
        ?string $buyerVat = null,
        ?string $notes = null,
        ?int $adminId = null,
    ): DeviceSale {
        return DB::transaction(function () use ($organization, $buyerName, $lines, $method, $paymentMeta, $buyerVat, $notes, $adminId) {
            $draft = $this->quote($organization, $buyerName, $lines, $method, $paymentMeta, $buyerVat, $notes);

            return $this->confirm($draft, $adminId);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve each requested device to a priced line.
     *
     * @param  array<int, array{device_id: int, qty: int}>  $lines
     * @return array<int, array{name: string, sku: string|null, qty: int, unitPrice: float, lineTotal: float}>
     */
    private function priceLines(array $lines): array
    {
        $devices = PlatformDevice::query()
            ->whereIn('id', array_column($lines, 'device_id'))
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($lines as $line) {
            $device = $devices->get($line['device_id']);
            abort_if($device === null, Response::HTTP_NOT_FOUND, __('api.record_not_found'));

            $qty = max(1, (int) $line['qty']);
            $unitPrice = round((float) $device->price, 2);

            $items[] = [
                'name' => $device->name,
                'sku' => $device->sku,
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'lineTotal' => round($unitPrice * $qty, 2),
            ];
        }

        abort_if($items === [], Response::HTTP_UNPROCESSABLE_ENTITY, __('api.record_not_found'));

        return $items;
    }

    /**
     * A deterministic canonical string over the sale's tax-relevant fields — the input to
     * the chained hash. The DEV prefix keeps it distinct from a subscription invoice that
     * happens to carry the same numbers.
     */
    private function canonical(DeviceSale $sale, int $icv, string $pih, string $timestamp): string
    {
        $seller = $this->chain->seller();

        return implode('|', [
            'DEV',
            $icv,
            $pih,
            $timestamp,
            $seller['name'],
            (string) $seller['vat'],
            (string) $sale->buyer_name,
            (string) $sale->buyer_vat,
            json_encode($sale->items, JSON_UNESCAPED_UNICODE),
            Zatca::money((float) $sale->subtotal),
            Zatca::money((float) $sale->vat),
            Zatca::money((float) $sale->total),
        ]);
    }
}
