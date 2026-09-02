<?php

namespace App\Services\Zatca;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\Organization;
use App\Models\ZatcaInvoice;
use App\Support\Zatca;
use App\Support\ZatcaPhase2;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds ZATCA invoices for an order.
 *
 * Phase 1 is computed on the fly (never stored). Phase 2 is generated once and stored,
 * idempotent on the order, with a per-organization ICV sequence guarded by a unique index
 * and a self-correcting retry loop under concurrency.
 */
class ZatcaInvoiceService
{
    private const MAX_ATTEMPTS = 8;

    /**
     * The full (unstored) Phase 1 tax invoice for an order.
     *
     * @return array<string, mixed>
     */
    public function phaseOneInvoice(Order $order, Organization $organization): array
    {
        $data = $this->invoiceInput($order, $organization);
        $order->loadMissing('branch:id,name', 'payments');

        return [
            'seller' => [
                'name' => $organization->name,
                'vat_number' => $organization->vat_number,
                'cr_number' => $organization->cr_number,
                'address' => $organization->address,
            ],
            'buyer' => [
                'name' => $order->customer?->name,
                'phone' => $order->customer?->phone,
            ],
            'order_no' => $order->order_no,
            'branch_name' => $order->branch?->name,
            'timestamp' => $data['timestamp'],
            'currency' => $data['currency'],
            'receipt' => [
                'enabled' => (bool) $organization->receipt_enabled,
                'width' => (int) $organization->receipt_width,
                'footer' => $organization->receipt_footer,
            ],
            'items' => array_map(static fn (array $item) => [
                'name' => $item['name'],
                'service_type' => $item['serviceType'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unitPrice'],
                'line_total' => $item['lineTotal'],
            ], $data['items']),
            'tax_rate' => $data['vatRate'],
            'subtotal' => $data['subtotal'],
            'discount_total' => $data['discountTotal'],
            'vat_total' => $data['vatTotal'],
            'grand_total' => $data['grandTotal'],
            'payment_status' => $order->payment_status->value,
            'paid_total' => round((float) $order->paid_total, 2),
            'remaining' => $order->remaining(),
            'payments' => $order->payments->sortBy('id')->values()->map(fn ($p) => [
                'method' => $p->method->value,
                'amount' => round((float) $p->amount, 2),
                'cash_tendered' => $p->cash_tendered === null ? null : round((float) $p->cash_tendered, 2),
                'reference' => $p->reference,
            ]),
            'qr' => Zatca::qrPayload(
                $organization->name,
                (string) ($organization->vat_number ?? ''),
                $data['timestamp'],
                Zatca::money($data['grandTotal']),
                Zatca::money($data['vatTotal']),
            ),
        ];
    }

    /**
     * Generate (or return the existing) stored Phase 2 invoice for an order.
     */
    public function generate(Order $order, Organization $organization): ZatcaInvoice
    {
        $existing = ZatcaInvoice::query()->where('order_id', $order->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        abort_if(empty($organization->vat_number), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.org_not_vat_registered'));

        $base = $this->invoiceInput($order, $organization);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $last = ZatcaInvoice::query()->forOrganization($organization->getKey())->orderByDesc('icv')->first();
            $icv = ($last?->icv ?? 0) + 1;
            $pih = $last?->hash ?? ZatcaPhase2::GENESIS_PIH;
            $uuid = (string) Str::uuid();

            $xml = ZatcaPhase2::buildInvoiceXml([...$base, 'uuid' => $uuid, 'icv' => $icv, 'pih' => $pih]);
            $hash = ZatcaPhase2::sha256Base64($xml);
            $qr = ZatcaPhase2::qrPayloadV2(
                $organization->name,
                (string) ($organization->vat_number ?? ''),
                $base['timestamp'],
                Zatca::money($base['grandTotal']),
                Zatca::money($base['vatTotal']),
                $hash,
            );

            try {
                return ZatcaInvoice::query()->create([
                    'order_id' => $order->getKey(),
                    'organization_id' => $organization->getKey(),
                    'icv' => $icv,
                    'uuid' => $uuid,
                    'pih' => $pih,
                    'hash' => $hash,
                    'xml' => $xml,
                    'qr' => $qr,
                ]);
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }

                // Another call created the invoice for this order → return it.
                $forOrder = ZatcaInvoice::query()->where('order_id', $order->getKey())->first();
                if ($forOrder !== null) {
                    return $forOrder;
                }

                // Otherwise an ICV race: recompute the next ICV and retry.
            }
        }

        $forOrder = ZatcaInvoice::query()->where('order_id', $order->getKey())->first();
        abort_if($forOrder === null, Response::HTTP_SERVICE_UNAVAILABLE, __('api.zatca_generation_failed'));

        return $forOrder;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The shared invoice data derived from the order.
     *
     * @return array<string, mixed>
     */
    private function invoiceInput(Order $order, Organization $organization): array
    {
        $order->loadMissing('items.service:id,name,service_type', 'customer:id,name,phone');

        $vatRate = (float) ($order->tax_rate ?? $organization->tax_rate ?? 15);
        $subtotal = round((float) $order->subtotal, 2);
        $discountTotal = round((float) $order->discount_total, 2);

        $garmentNames = $this->garmentNames($order);

        $items = $order->items->map(function ($item) use ($garmentNames) {
            $service = (string) ($item->service?->name ?? __('api.service'));
            $garment = $garmentNames[$item->garment_type_id] ?? null;

            return [
                'name' => $garment ? "{$service} ({$garment})" : $service,
                'serviceType' => $item->service?->service_type?->value,
                'quantity' => round((float) $item->quantity, 2),
                'unitPrice' => round((float) $item->unit_price, 2),
                'lineTotal' => round((float) $item->line_total, 2),
            ];
        })->all();

        return [
            'orderNo' => (string) $order->order_no,
            'timestamp' => Zatca::timestamp($order->created_at),
            'currency' => $organization->default_currency ?? 'SAR',
            'sellerName' => $organization->name,
            'vatNumber' => (string) ($organization->vat_number ?? ''),
            'sellerAddress' => $organization->address,
            'buyerName' => $order->customer?->name,
            'vatRate' => $vatRate,
            'subtotal' => $subtotal,
            'discountTotal' => $discountTotal,
            'taxableTotal' => round($subtotal - $discountTotal, 2),
            'vatTotal' => round((float) $order->tax_total, 2),
            'grandTotal' => round((float) $order->grand_total, 2),
            'items' => $items,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function garmentNames(Order $order): array
    {
        $ids = $order->items->pluck('garment_type_id')->filter()->unique()->all();

        if ($ids === []) {
            return [];
        }

        return GarmentType::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
